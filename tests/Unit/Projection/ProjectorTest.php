<?php

declare(strict_types=1);

namespace Test\Unit\Projection;

use Ark4ne\ApiRevisions\Compilation\ShapeSelector;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Schema;
use ArrayObject;
use DateTimeImmutable;
use LogicException;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Customer;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Enums\OrderStatus;
use Test\TestCase;

final class ProjectorTest extends TestCase
{
    public function test_only_visible_sources_are_evaluated(): void
    {
        $read = [];
        $head = [
            'title' => Schema::string(static function () use (&$read): string {
                $read[] = 'title';

                return 'Book';
            }),
            ...Schema::since(ApiRevision::Atlas, [
                'notes' => Schema::string(static function () use (&$read): string {
                    $read[] = 'notes';

                    return 'Fragile';
                }),
            ]),
            ...Schema::until(ApiRevision::Winter25, [
                'name' => Schema::string(static function () use (&$read): string {
                    $read[] = 'name';

                    return 'Ada';
                }),
            ]),
        ];

        self::assertSame(['title' => 'Book', 'name' => 'Ada'], self::project($head, ApiRevision::Genesis, []));
        self::assertSame(['title' => 'Book', 'notes' => 'Fragile'], self::project($head, ApiRevision::Atlas, []));
        self::assertSame(['title', 'name', 'title', 'notes'], $read);
    }

    public function test_a_field_is_written_under_its_name_at_the_revision(): void
    {
        $head = [
            'title' => Schema::string(),
            'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
        ];
        $subject = ['title' => 'Book', 'status' => OrderStatus::Paid];

        self::assertSame(['title' => 'Book', 'state' => 'paid'], self::project($head, ApiRevision::Genesis, $subject));
        self::assertSame(['title' => 'Book', 'state' => 'paid'], self::project($head, ApiRevision::Winter25, $subject));
        self::assertSame(['title' => 'Book', 'status' => 'paid'], self::project($head, ApiRevision::Spring26, $subject));
    }

    public function test_a_renamed_field_reads_its_head_key(): void
    {
        $head = ['status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state')];

        self::assertSame(['state' => 'paid'], self::project($head, ApiRevision::Genesis, ['status' => 'paid', 'state' => 'shipped']));
    }

    public function test_a_dotted_source_stops_at_null(): void
    {
        $head = ['customer_name' => Schema::string('customer.firstName')->nullable()];

        self::assertSame(['customer_name' => null], self::project($head, ApiRevision::Atlas, ['customer' => null]));
        self::assertSame(['customer_name' => 'Ada'], self::project($head, ApiRevision::Atlas, ['customer' => new Customer('Ada', 'Lovelace')]));
    }

    public function test_a_source_closure_receives_the_peeked_value_and_the_subject(): void
    {
        $received = [];
        $head = [
            'title' => Schema::string(static function (mixed $value, mixed $subject) use (&$received): string {
                $received[] = [$value, $subject];

                return 'Book';
            }),
        ];

        self::project($head, ApiRevision::Atlas, ['title' => 'Raw']);
        self::project($head, ApiRevision::Atlas, ['other' => 'Raw']);

        self::assertSame([['Raw', ['title' => 'Raw']], [null, ['other' => 'Raw']]], $received);
    }

    public function test_when_receives_the_normalized_head_value(): void
    {
        $received = [];
        $head = [
            'total' => Schema::int()
                ->when(static function (mixed $value, mixed $subject) use (&$received): bool {
                    $received[] = [$value, $subject];

                    return true;
                })
                ->was(ApiRevision::Winter25, schema: Schema::string()),
        ];

        self::assertSame(['total' => '1250'], self::project($head, ApiRevision::Genesis, ['total' => '1250']));
        self::assertSame([[1250, ['total' => '1250']]], $received);
    }

    public function test_conversions_receive_normalized_values(): void
    {
        $received = [];
        $head = [
            'total' => Schema::int()
                ->was(ApiRevision::Winter25, schema: Schema::string(), down: static function (int $cents) use (&$received): string {
                    $received[] = $cents;

                    return number_format($cents / 100, 2, '.', '');
                })
                ->was(ApiRevision::Genesis, schema: Schema::float()),
        ];

        self::assertSame(['total' => 1250], self::project($head, ApiRevision::Atlas, ['total' => '1250']));
        self::assertSame(['total' => '12.50'], self::project($head, ApiRevision::Winter25, ['total' => '1250']));
        self::assertSame(['total' => 12.5], self::project($head, ApiRevision::Genesis, ['total' => 1250.0]));
        self::assertSame([1250, 1250], $received);
    }

    public function test_a_down_closure_receives_the_subject(): void
    {
        $subject = new ArrayObject(['total' => 1250, 'currency' => 'EUR']);
        $head = [
            'total' => Schema::int()->was(
                ApiRevision::Winter25,
                schema: Schema::string(),
                down: static fn(int $cents, ArrayObject $order): string => sprintf('%d %s', $cents, $order->offsetGet('currency') === 'EUR' ? 'EUR' : '?'),
            ),
        ];

        self::assertSame(['total' => '1250 EUR'], self::project($head, ApiRevision::Genesis, $subject));
    }

    public function test_the_type_in_force_at_the_revision_serializes(): void
    {
        $head = ['placed_at' => Schema::dateTime()->was(ApiRevision::Winter25, schema: Schema::date(), down: static fn(DateTimeImmutable $at): DateTimeImmutable => $at)];
        $subject = ['placed_at' => new DateTimeImmutable('2026-03-01 10:30:00+00:00')];
        $projector = new Projector(new Configuration(dateFormat: 'd/m/Y'));

        self::assertSame(['placed_at' => '2026-03-01T10:30:00+00:00'], self::project($head, ApiRevision::Atlas, $subject));
        self::assertSame(['placed_at' => '01/03/2026'], $projector->project(self::shape($head, ApiRevision::Winter25, new Configuration(dateFormat: 'd/m/Y')), $head, $subject));
    }

    public function test_null_is_rendered_null_in_every_shape(): void
    {
        $called = false;
        $head = [
            'total' => Schema::int()->nullable()
                ->was(ApiRevision::Winter25, schema: Schema::string()->nullable(), down: static function () use (&$called): string {
                    $called = true;

                    return 'converted';
                })
                ->was(ApiRevision::Genesis, schema: Schema::float()->nullable()),
        ];

        foreach (ApiRevision::cases() as $revision) {
            self::assertSame(['total' => null], self::project($head, $revision, ['total' => null]));
        }
        self::assertFalse($called);
    }

    public function test_a_failing_condition_omits_the_field(): void
    {
        $head = [
            'title' => Schema::string()->when(static fn(string $title): bool => $title !== ''),
            'notes' => Schema::string()->when(static fn(): bool => true)->when(static fn(): bool => false),
            'ref' => Schema::string(),
            'code' => Schema::string('ref')->when(static fn(): int => 1),
        ];

        self::assertSame(['title' => 'Book', 'ref' => 'A1'], self::project($head, ApiRevision::Atlas, ['title' => 'Book', 'notes' => 'x', 'ref' => 'A1']));
        self::assertSame(['ref' => 'A1'], self::project($head, ApiRevision::Atlas, ['title' => '', 'notes' => 'x', 'ref' => 'A1']));
    }

    public function test_a_presence_condition_runs_before_the_source(): void
    {
        $head = [
            'title' => Schema::string(static fn(): never => throw new LogicException('The source ran.'))->whenHas('title'),
            'customer' => Schema::string()->whenHas('customer.name'),
        ];

        self::assertSame([], self::project($head, ApiRevision::Atlas, ['customer' => []]));
        self::assertSame(['customer' => 'Ada'], self::project([
            'customer' => Schema::string('name')->whenHas('name')->whenHas(),
        ], ApiRevision::Atlas, ['name' => 'Ada']));
    }

    public function test_a_presence_without_key_checks_the_source_path_or_else_the_declared_key(): void
    {
        $head = [
            'ref' => Schema::string('reference')->whenHas(),
            'title' => Schema::string()->whenHas(),
            'label' => Schema::string(static fn(): string => 'computed')->whenHas(),
        ];

        self::assertSame(['ref' => 'A1'], self::project($head, ApiRevision::Atlas, ['reference' => 'A1', 'ref' => 'no']));
        self::assertSame(['title' => 'Book', 'label' => 'computed'], self::project($head, ApiRevision::Atlas, ['title' => 'Book', 'label' => 'x']));
    }

    public function test_projecting_twice_gives_the_same_result(): void
    {
        $head = [
            'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
            'total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string()),
        ];
        $subject = new ArrayObject(['status' => 'paid', 'total' => 1250]);
        $shape = self::shape($head, ApiRevision::Genesis);
        $projector = new Projector();

        $first = $projector->project($shape, $head, $subject);

        self::assertSame($first, $projector->project($shape, $head, $subject));
        self::assertSame(['state' => 'paid', 'total' => '1250'], $first);
        self::assertSame(['status' => 'paid', 'total' => 1250], $subject->getArrayCopy());
    }

    public function test_a_refused_value_throws_naming_the_class_the_field_and_the_revision(): void
    {
        $head = ['total' => Schema::int()];
        $shape = self::shape($head, ApiRevision::Atlas)->of(OrderDeclaration::class, DeclarationKind::Response);

        $this->expectExceptionObject(ValueException::refused(OrderDeclaration::class, 'total', ApiRevision::Atlas, 'int', 'string'));

        (new Projector())->project($shape, $head, ['total' => 'abc']);
    }

    public function test_a_failed_conversion_throws_a_value_error(): void
    {
        $head = ['total' => Schema::float()->was(ApiRevision::Winter25, schema: Schema::int())];

        try {
            self::project($head, ApiRevision::Genesis, ['total' => 1.0e30]);
            self::fail('The conversion should fail.');
        } catch (ValueException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringEndsWith('field total at Test\\Fixtures\\ApiRevision::Genesis: float cannot be converted to int.', $exception->getMessage());
            self::assertStringNotContainsString('1.0E+30', $exception->getMessage());
        }
    }

    public function test_the_chains_are_bound_to_the_converters_given(): void
    {
        $head = ['total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string())];

        $this->expectException(ValueException::class);

        (new Projector(converters: new Converters()))->project(self::shape($head, ApiRevision::Genesis), $head, ['total' => 1250]);
    }

    public function test_an_object_without_source_reads_the_subject_of_its_parent(): void
    {
        $head = ['meta' => Schema::object(['ref' => Schema::string()])];

        self::assertSame(['meta' => ['ref' => 'A1']], self::project($head, ApiRevision::Atlas, ['ref' => 'A1']));
    }

    public function test_descriptors_without_the_was_schema_of_the_shape_are_refused(): void
    {
        $shape = self::shape(['total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string())], ApiRevision::Genesis);

        $this->expectExceptionObject(DefinitionException::missingDescriptor('object', 'total'));

        (new Projector())->project($shape, ['total' => Schema::int()->was(ApiRevision::Winter25, name: 'amount')], ['total' => 1250]);
    }

    public function test_descriptors_missing_a_field_of_the_shape_are_refused(): void
    {
        $shape = self::shape(['title' => Schema::string(), 'total' => Schema::int()], ApiRevision::Atlas);

        $this->expectExceptionObject(DefinitionException::missingDescriptor('object', 'total'));

        (new Projector())->project($shape, ['title' => Schema::string()], ['title' => 'Book']);
    }

    /**
     * @param array<string, Descriptor<mixed>> $head
     *
     * @return array<string, mixed>
     */
    private static function project(array $head, Revision $revision, mixed $subject): array
    {
        return (new Projector())->project(self::shape($head, $revision), $head, $subject);
    }

    /**
     * @param array<string, Descriptor<mixed>> $head
     */
    private static function shape(array $head, Revision $revision, Configuration $configuration = new Configuration()): \Ark4ne\ApiRevisions\Compilation\Shape
    {
        return ShapeSelector::at(
            array_map(static fn(Descriptor $descriptor): Descriptor => $descriptor->configured($configuration), $head),
            $revision,
            Direction::Down,
        );
    }
}
