<?php

declare(strict_types=1);

namespace Test\Unit\Projection;

use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use DateTimeImmutable;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Customer;
use Test\Fixtures\Declarations\ArticleDeclaration;
use Test\Fixtures\Declarations\BoundDeclaration;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\EagerDeclaration;
use Test\Fixtures\Declarations\ForeignResourceDeclaration;
use Test\Fixtures\Declarations\InvalidDeclaration;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Declarations\ShippingDeclaration;
use Test\Fixtures\Declarations\ThrowingDeclaration;
use Test\Fixtures\Declarations\WarehouseDeclaration;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Order;
use Test\TestCase;

/**
 * The invariant of docs/architecture.md §7: for every fixture at every revision, the projected keys, recursively, are
 * the names of the `Shape`, and the projection validates against the exported JSON Schema of its revision (US 10.1).
 */
final class ProjectionInvariantTest extends TestCase
{
    private const array ADDRESS = ['street' => '1 Main Street', 'zip' => '75001'];

    /**
     * The response declarations that cannot be projected on purpose: they test refusals.
     */
    private const array EXCLUDED = [
        EagerDeclaration::class,
        InvalidDeclaration::class,
        ThrowingDeclaration::class,
        ForeignResourceDeclaration::class,
        // declared by each check test
        CheckedDeclaration::class,
    ];

    /**
     * @return iterable<string, array{class-string<Revisioned>, mixed, Revision}>
     */
    public static function fixtures(): iterable
    {
        foreach (self::subjects() as $class => $subject) {
            foreach (ApiRevision::cases() as $revision) {
                yield "{$class} at {$revision->name}" => [$class, $subject, $revision];
            }
        }
    }

    public function test_every_response_fixture_is_projected_or_excluded(): void
    {
        $declarations = [];
        $files = glob(__DIR__ . '/../../Fixtures/Declarations/*.php');
        self::assertNotFalse($files);
        foreach ($files as $file) {
            $class = 'Test\\Fixtures\\Declarations\\' . basename($file, '.php');
            if (is_subclass_of($class, Revisioned::class) && in_array(DeclarationKind::Response, $class::declarationKinds(), true)) {
                $declarations[] = $class;
            }
        }
        $covered = [...array_keys(self::subjects()), ...self::EXCLUDED];
        sort($declarations);
        sort($covered);

        self::assertSame($declarations, $covered);
    }

    /**
     * @param class-string<Revisioned> $class
     */
    #[DataProvider('fixtures')]
    public function test_projection_and_shape_agree_for_every_fixture_at_every_revision(string $class, mixed $subject, Revision $revision): void
    {
        $shapes = new Shapes();
        $shape = $shapes->for($class, DeclarationKind::Response, $revision);

        $projected = (new Projector(shapes: $shapes))->project($shape, $class::describeRevisioned(DeclarationKind::Response, $subject), $subject);

        self::assertAgree($shapes, $shape, $projected, $class);
    }

    /**
     * @param class-string<Revisioned> $class
     */
    #[DataProvider('fixtures')]
    public function test_a_projected_document_matches_the_schema_of_its_revision(string $class, mixed $subject, Revision $revision): void
    {
        $shapes = new Shapes();
        $shape = $shapes->for($class, DeclarationKind::Response, $revision);
        $projected = (new Projector(shapes: $shapes))->project($shape, $class::describeRevisioned(DeclarationKind::Response, $subject), $subject);

        $schema = (new JsonSchemaExporter($shapes))->export($class, DeclarationKind::Response, $revision);
        $result = (new Validator())->validate(
            json_decode(json_encode($projected, JSON_THROW_ON_ERROR)),
            json_encode(JsonSchemaExporter::objects($schema), JSON_THROW_ON_ERROR),
        );

        $error = $result->error();
        self::assertNull($error, $error === null ? '' : json_encode((new ErrorFormatter())->format($error), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<array-key, mixed> $projected
     */
    private static function assertAgree(Shapes $shapes, Shape $shape, array $projected, string $path): void
    {
        self::assertSame($shape->names(), array_map(strval(...), array_keys($projected)), $path);
        foreach ($shape->fields as $field) {
            $value = $projected[$field->name];
            if ($value === null) {
                self::assertTrue($field->nullable, "{$path}.{$field->name} is null but not nullable");
            } else {
                self::assertNested($shapes, $field, $value, "{$path}.{$field->name}");
            }
        }
    }

    private static function assertNested(Shapes $shapes, FieldDefinition $field, mixed $value, string $path): void
    {
        if ($field->schemaChange !== null) {
            // a `was(schema:)` in force: its conversion gives the value, its type describes it (US 10.1 validates it)
            return;
        }
        if ($field->children !== null) {
            self::assertAgree($shapes, $field->children, self::object($value, $path), $path);
        } elseif ($field->items !== null) {
            self::assertIsArray($value, $path);
            foreach ($value as $index => $item) {
                if ($item === null) {
                    self::assertTrue($field->items->nullable, "{$path}[{$index}] is null but not nullable");
                } else {
                    self::assertNested($shapes, $field->items, $item, "{$path}[{$index}]");
                }
            }
        } elseif ($field->type->resource !== null) {
            self::assertAgree($shapes, $shapes->for($field->type->resource, DeclarationKind::Response, $field->headAt), self::object($value, $path), $path);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function object(mixed $value, string $path): array
    {
        if ($value instanceof stdClass) {
            return (array) $value;
        }
        self::assertIsArray($value, $path);

        return $value;
    }

    /**
     * A subject of every projected response fixture, filled so that every composite holds a value.
     *
     * @return array<class-string<Revisioned>, mixed>
     */
    private static function subjects(): array
    {
        return [
            OrderDeclaration::class => self::order([self::order([])]),
            CustomerDeclaration::class => ['name' => 'Ada Lovelace', 'orders' => [self::order([])]],
            BoundDeclaration::class => ['label' => 'ada'],
            CountingDeclaration::class => ['title' => 'Book'],
            ArticleDeclaration::class => ['title' => 'Tides', 'views' => 12, 'body' => null, 'published' => true, 'author' => ['name' => 'Ada']],
            WarehouseDeclaration::class => ['address' => self::ADDRESS, 'docks' => [self::ADDRESS]],
            ShippingDeclaration::class => [
                'from' => ['address' => null, 'docks' => [self::ADDRESS]],
                'to' => self::ADDRESS,
                'price' => ['amount' => 1250, 'currency' => 'EUR'],
                'fee' => null,
            ],
        ];
    }

    /**
     * @param list<Order> $orders the orders of its customer
     */
    private static function order(array $orders): Order
    {
        return new Order(
            'Book',
            OrderStatus::Paid,
            1250,
            new DateTimeImmutable('2026-03-01 10:30:00+00:00'),
            new DateTimeImmutable('2026-03-04'),
            ['name' => 'Ada Lovelace', 'orders' => $orders],
            ['gift'],
            'Fragile',
            new Customer('Ada', 'Lovelace'),
        );
    }
}
