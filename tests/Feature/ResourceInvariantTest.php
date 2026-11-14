<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\JsonResource;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Customer as PlainCustomer;
use Test\Fixtures\Eloquent\Customer;
use Test\Fixtures\Eloquent\Order;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Order as PlainOrder;
use Test\Fixtures\Resources\BrokenResource;
use Test\Fixtures\Resources\CustomerResource;
use Test\Fixtures\Resources\InvertedResource;
use Test\Fixtures\Resources\NativeResource;
use Test\Fixtures\Resources\NoteResource;
use Test\Fixtures\Resources\OrderResource;
use Test\Fixtures\Resources\PlainOrderResource;
use Test\Fixtures\Resources\ReachingResource;
use Test\Fixtures\Resources\WrapperResource;

/**
 * The invariant of `ProjectionInvariantTest` for the `RevisionedResource` fixtures: rendered with every conditional
 * field present, a resource gives exactly the names of its shape at every revision, nested resources included. The
 * JSON Schema half comes with the export (US 10.1).
 */
final class ResourceInvariantTest extends FeatureTestCase
{
    /**
     * Native conditions and plain values render keys out of the shape by design (model.md D61); a broken declaration
     * does not compile; an empty rendering misses its shape on purpose (the contract test rejects it).
     */
    private const array EXCLUDED = [
        NativeResource::class,
        ReachingResource::class,
        WrapperResource::class,
        BrokenResource::class,
        InvertedResource::class,
        NoteResource::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->application()['config']->set('revisions.enum', ApiRevision::class);
    }

    /**
     * @return iterable<string, array{class-string<JsonResource>, Revision}>
     */
    public static function fixtures(): iterable
    {
        foreach ([OrderResource::class, CustomerResource::class, PlainOrderResource::class] as $class) {
            foreach (ApiRevision::cases() as $revision) {
                yield "{$class} at {$revision->name}" => [$class, $revision];
            }
        }
    }

    public function test_every_resource_fixture_is_rendered_or_excluded(): void
    {
        $resources = [];
        $files = glob(__DIR__ . '/../Fixtures/Resources/*.php');
        self::assertNotFalse($files);
        foreach ($files as $file) {
            $resources[] = 'Test\\Fixtures\\Resources\\' . basename($file, '.php');
        }
        $covered = [...array_unique(array_column(iterator_to_array(self::fixtures()), 0)), ...self::EXCLUDED];
        sort($resources);
        sort($covered);

        self::assertSame($resources, $covered);
    }

    /**
     * @param class-string<JsonResource> $class
     */
    #[DataProvider('fixtures')]
    public function test_rendering_and_shape_agree_for_every_resource_at_every_revision(string $class, Revision $revision): void
    {
        $shapes = $this->application()->make(Shapes::class);
        $resource = new $class(self::subject($class));

        $rendered = ApiRevision::using($revision, static fn(): array => $resource->resolve());

        self::assertAgree($shapes, $shapes->for($class, DeclarationKind::Response, $revision), $rendered, $class);
    }

    /**
     * @param array<array-key, mixed> $rendered
     */
    private static function assertAgree(Shapes $shapes, Shape $shape, array $rendered, string $path): void
    {
        self::assertSame($shape->names(), array_map(strval(...), array_keys($rendered)), $path);
        foreach ($shape->fields as $field) {
            $value = $rendered[$field->name];
            $resource = $field->items?->type->resource ?? $field->type->resource;
            if ($resource === null || !is_subclass_of($resource, Revisioned::class) || !is_array($value)) {
                continue;
            }
            $nested = $shapes->for($resource, DeclarationKind::Response, $field->headAt);
            foreach ($field->items !== null ? $value : [$value] as $item) {
                self::assertIsArray($item, "{$path}.{$field->name}");
                self::assertAgree($shapes, $nested, $item, "{$path}.{$field->name}");
            }
        }
    }

    private static function subject(string $class): mixed
    {
        $order = new Order([
            'title' => 'Lamp',
            'status' => OrderStatus::Paid,
            'total_cents' => 1250,
            'placed_at' => '2026-03-01 10:30:00',
            'tags' => ['gift'],
            'notes' => null,
        ]);
        $customer = new Customer(['name' => 'Ada Lovelace']);
        $customer->setRelation('orders', new Collection());
        $order->setRelation('customer', $customer);

        return match ($class) {
            OrderResource::class => $order,
            CustomerResource::class => $customer,
            default => new PlainOrder(
                'Lamp',
                OrderStatus::Paid,
                1250,
                new DateTimeImmutable('2026-03-01 10:30:00+00:00'),
                new DateTimeImmutable('2026-03-04'),
                ['name' => 'Ada Lovelace', 'orders' => []],
                ['gift'],
                null,
                new PlainCustomer('Ada', 'Lovelace'),
            ),
        };
    }
}
