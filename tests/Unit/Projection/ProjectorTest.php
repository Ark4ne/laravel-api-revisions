<?php

declare(strict_types=1);

namespace Test\Unit\Projection;

use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Compilation\ShapeSelector;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Projection\LenientValues;
use Ark4ne\ApiRevisions\Projection\PlainSourceReader;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Projection\SourceReader;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use ArrayObject;
use DateTimeImmutable;
use LogicException;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Customer;
use Test\Fixtures\Declarations\BoundDeclaration;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\RelationSchema;
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

    public function test_a_custom_type_declaring_its_identity_keeps_its_value_across_fragments(): void
    {
        TypeRegistry::register('relation', RelationSchema::class);
        $head = ['buyer' => (new RelationSchema('customers'))->was(ApiRevision::Winter25, schema: new RelationSchema('buyers'))];
        $subject = ['buyer' => ['type' => 'customers', 'id' => '7']];

        $shape = ShapeSelector::at($head, ApiRevision::Genesis, Direction::Down, TypeRegistry::converters());

        self::assertSame([], array_values($shape->fields)[0]->chain->steps);
        self::assertSame(['buyer' => ['type' => 'customers', 'id' => '7']], (new Projector())->project($shape, $head, $subject));
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

    public function test_a_relation_presence_asks_the_reader_whether_it_is_loaded(): void
    {
        $head = [
            'customer' => Schema::string('customer.name')->whenLoaded('customer'),
            'title' => Schema::string(static fn(): never => throw new LogicException('The source ran.'))->whenLoaded('missing'),
        ];
        $reader = new class implements SourceReader {
            /** @var list<string> */
            public array $calls = [];

            public function read(mixed $subject, array $path): mixed
            {
                return (new PlainSourceReader())->read($subject, $path);
            }

            public function has(mixed $subject, array $path): bool
            {
                $this->calls[] = 'has:' . implode('.', $path);

                return true;
            }

            public function loaded(mixed $subject, array $path): bool
            {
                $this->calls[] = 'loaded:' . implode('.', $path);

                return $path === ['customer'];
            }

            public function peek(mixed $subject, string $key): mixed
            {
                return null;
            }
        };

        $projected = (new Projector(reader: $reader))->project(self::shape($head, ApiRevision::Atlas), $head, ['customer' => ['name' => 'Ada']]);

        self::assertSame(['customer' => 'Ada'], $projected);
        self::assertSame(['loaded:customer', 'loaded:missing'], $reader->calls);
    }

    public function test_a_relation_presence_on_a_plain_subject_checks_the_key(): void
    {
        $head = ['customer' => Schema::string('customer.name')->nullable()->whenLoaded('customer')];

        self::assertSame(['customer' => null], self::project($head, ApiRevision::Atlas, ['customer' => null]));
        self::assertSame([], self::project($head, ApiRevision::Atlas, []));
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

    public function test_a_child_renamed_in_an_object_is_projected_under_its_old_name(): void
    {
        $head = [
            'address' => Schema::object([
                'street' => Schema::string(),
                'zip' => Schema::string('postcode')->was(ApiRevision::Winter25, name: 'postal_code'),
            ], 'shippingAddress'),
        ];
        $subject = ['shippingAddress' => ['street' => 'Main street', 'postcode' => '75001']];

        self::assertSame(['address' => ['street' => 'Main street', 'postal_code' => '75001']], self::project($head, ApiRevision::Genesis, $subject));
        self::assertSame(['address' => ['street' => 'Main street', 'zip' => '75001']], self::project($head, ApiRevision::Atlas, $subject));
    }

    public function test_an_object_with_a_source_reads_its_children_on_the_source_value(): void
    {
        $head = ['address' => Schema::object(['street' => Schema::string()], 'shippingAddress')];

        self::assertSame(
            ['address' => ['street' => 'Main street']],
            self::project($head, ApiRevision::Atlas, ['street' => 'Other', 'shippingAddress' => new ArrayObject(['street' => 'Main street'])]),
        );
    }

    public function test_an_object_without_source_reads_its_children_on_the_parent_subject(): void
    {
        $head = ['meta' => Schema::object(['ref' => Schema::string(), 'title' => Schema::string()->whenHas()])];

        self::assertSame(['meta' => ['ref' => 'A1']], self::project($head, ApiRevision::Atlas, ['ref' => 'A1']));
    }

    public function test_a_null_object_stays_null_and_an_empty_one_is_a_json_object(): void
    {
        $head = ['address' => Schema::object(['street' => Schema::string()->whenHas()], 'shippingAddress')->nullable()];

        self::assertSame(['address' => null], self::project($head, ApiRevision::Atlas, ['shippingAddress' => null]));
        self::assertSame('{"address":{}}', json_encode(self::project($head, ApiRevision::Atlas, ['shippingAddress' => []])));
    }

    public function test_a_list_of_objects_is_projected_item_by_item(): void
    {
        $head = [
            'lines' => Schema::list(Schema::object([
                'sku' => Schema::string(),
                'quantity' => Schema::int()->was(ApiRevision::Winter25, name: 'qty', schema: Schema::string()),
            ])),
        ];
        $subject = ['lines' => [['sku' => 'A1', 'quantity' => '2'], new ArrayObject(['sku' => 'B2', 'quantity' => 3])]];

        self::assertSame(['lines' => [['sku' => 'A1', 'qty' => '2'], ['sku' => 'B2', 'qty' => '3']]], self::project($head, ApiRevision::Genesis, $subject));
        self::assertSame(['lines' => [['sku' => 'A1', 'quantity' => 2], ['sku' => 'B2', 'quantity' => 3]]], self::project($head, ApiRevision::Atlas, $subject));
    }

    public function test_the_items_of_a_list_follow_their_own_history(): void
    {
        $head = ['tags' => Schema::list(Schema::int()->was(ApiRevision::Winter25, schema: Schema::string()), 'tagIds')];

        self::assertSame(['tags' => ['1', '2']], self::project($head, ApiRevision::Genesis, ['tagIds' => (static fn() => yield from [1, 2])()]));
        self::assertSame(['tags' => [1, 2]], self::project($head, ApiRevision::Atlas, ['tagIds' => ['a' => 1, 'b' => 2]]));
    }

    public function test_an_item_whose_condition_fails_is_dropped_from_the_list(): void
    {
        $head = [
            'tags' => Schema::list(Schema::string()->when(static fn(string $tag): bool => $tag !== '')),
            'names' => Schema::list(Schema::string('name')->whenHas('name')),
            'codes' => Schema::list(Schema::string()->whenHas()),
            'firsts' => Schema::list(Schema::rule([])->whenHas()->whenHas('first')),
        ];
        $subject = [
            'tags' => ['a', '', 'b'], 'names' => [['name' => 'Ada'], [], ['name' => 'Grace']], 'codes' => ['x', 'y'],
            'firsts' => [['first' => 'Ada'], ['last' => 'Hopper']],
        ];

        self::assertSame(
            ['tags' => ['a', 'b'], 'names' => ['Ada', 'Grace'], 'codes' => ['x', 'y'], 'firsts' => [['first' => 'Ada']]],
            self::project($head, ApiRevision::Atlas, $subject),
        );
    }

    public function test_a_composite_condition_and_conversion_receive_its_projected_children(): void
    {
        $received = [];
        $head = [
            'address' => Schema::object(['street' => Schema::string('road')])
                ->when(static function (mixed $children) use (&$received): bool {
                    $received[] = $children;

                    return true;
                })
                ->was(ApiRevision::Winter25, schema: Schema::string(), down: static function (array $children) use (&$received): string {
                    $received[] = $children;

                    return count($children) . ' line';
                }),
        ];

        self::assertSame(['address' => '1 line'], self::project($head, ApiRevision::Genesis, ['road' => 'Main street']));
        self::assertSame([['street' => 'Main street'], ['street' => 'Main street']], $received);
    }

    public function test_a_nested_resource_is_projected_at_the_same_revision(): void
    {
        $head = ['customer' => Schema::resource(CustomerDeclaration::class, 'buyer')];
        $subject = ['buyer' => ['name' => 'Ada Lovelace', 'orders' => []]];

        self::assertSame(['customer' => ['full_name' => 'Ada Lovelace']], self::project($head, ApiRevision::Winter25, $subject));
        self::assertSame(['customer' => ['name' => 'Ada Lovelace', 'orders' => []]], self::project($head, ApiRevision::Spring26, $subject));
    }

    public function test_a_resource_cycle_is_projected_as_deep_as_the_data(): void
    {
        $order = [
            'title' => 'Book', 'status' => OrderStatus::Paid, 'total' => 1250, 'placed_at' => new DateTimeImmutable('2026-03-01T10:30:00+00:00'),
            'day' => new DateTimeImmutable('2026-03-04'), 'customer' => ['name' => 'Ada', 'orders' => []], 'tags' => ['gift'], 'notes' => null,
        ];
        $head = ['customer' => Schema::resource(CustomerDeclaration::class)];

        self::assertSame(['customer' => ['name' => 'Ada', 'orders' => [[
            'title' => 'Book', 'status' => 'paid', 'total' => 1250, 'placed_at' => '2026-03-01T10:30:00+00:00',
            'delivery' => ['day' => '2026-03-04'], 'customer' => ['name' => 'Ada', 'orders' => []], 'tags' => ['gift'],
            'notes' => null,
        ]]]], self::project($head, ApiRevision::Atlas, ['customer' => ['name' => 'Ada', 'orders' => [$order]]]));
    }

    public function test_a_nested_resource_is_built_on_its_own_subject(): void
    {
        $head = ['owner' => Schema::resource(BoundDeclaration::class, static fn(): array => ['label' => 'ada'])];

        self::assertSame(['owner' => ['label' => 'ADA']], self::project($head, ApiRevision::Atlas, []));
    }

    public function test_a_closure_source_of_an_item_receives_the_element(): void
    {
        $received = [];
        $head = [
            'names' => Schema::list(Schema::string(static function (mixed $value, mixed $subject) use (&$received): string {
                $received[] = [$value, $subject];

                return 'x';
            })),
        ];

        self::assertSame(['names' => ['x']], self::project($head, ApiRevision::Atlas, ['names' => [['first' => 'Ada']]]));
        self::assertSame([[['first' => 'Ada'], ['first' => 'Ada']]], $received);
    }

    public function test_the_conversion_of_a_composite_starts_from_the_head_shape_after_its_was(): void
    {
        $received = [];
        $head = [
            'address' => Schema::object([
                'line1' => Schema::string('street')->was(ApiRevision::Genesis, name: 'line'),
            ])->was(ApiRevision::Winter25, schema: Schema::object(['first_line' => Schema::string()]), down: static function (array $children) use (&$received): array {
                $received[] = $children;

                return ['first_line' => $children['line1'] ?? null];
            }),
        ];

        self::assertSame(['address' => ['first_line' => 'Main street']], self::project($head, ApiRevision::Genesis, ['street' => 'Main street']));
        self::assertSame([['line1' => 'Main street']], $received);
    }

    public function test_a_scalar_descriptor_on_a_composite_shape_is_refused(): void
    {
        $shape = self::shape(['address' => Schema::object([])], ApiRevision::Atlas);

        $this->expectExceptionObject(DefinitionException::missingDescriptor('object', 'address'));

        (new Projector())->project($shape, ['address' => Schema::string()], ['address' => 'x']);
    }

    public function test_strict_values_throw_on_a_refused_value(): void
    {
        $head = ['total' => Schema::int()];

        $this->expectExceptionObject(ValueException::refused('object', 'total', ApiRevision::Atlas, 'int', 'string'));

        (new Projector(new Configuration(strictValues: true)))->project(self::shape($head, ApiRevision::Atlas), $head, ['total' => 'abc']);
    }

    public function test_lenient_values_cast_and_report(): void
    {
        $reported = [];
        $head = [
            'total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string()),
            'lines' => Schema::list(Schema::string()),
            'title' => Schema::string(),
        ];
        $projector = self::lenient($reported);

        $projected = $projector->project(self::shape($head, ApiRevision::Genesis), $head, ['total' => '12.5', 'lines' => 'a,b', 'title' => 'Book']);

        self::assertSame(['total' => 12, 'lines' => null, 'title' => 'Book'], $projected);
        self::assertSame(['total', 'lines'], array_map(static fn(ValueException $error): string => $error->field, $reported));
    }

    public function test_a_refused_value_never_reaches_a_closure_when_lenient(): void
    {
        $reported = [];
        $head = [
            'status' => Schema::enum(OrderStatus::class)
                ->when(static fn(OrderStatus $status): bool => $status !== OrderStatus::Pending)
                ->was(ApiRevision::Winter25, schema: Schema::string(), down: static fn(OrderStatus $status): string => strtoupper($status->value)),
        ];

        $projected = self::lenient($reported)->project(self::shape($head, ApiRevision::Genesis), $head, ['status' => 'bogus']);

        self::assertSame(['status' => 'bogus'], $projected);
        self::assertCount(1, $reported);
    }

    public function test_a_null_given_by_a_conversion_on_a_non_nullable_field_is_a_value_error(): void
    {
        $head = ['total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string(), down: static fn(): ?string => null)];

        $this->expectExceptionObject(ValueException::nullNotAllowed('object', 'total', ApiRevision::Genesis));

        self::project($head, ApiRevision::Genesis, ['total' => 1250]);
    }

    public function test_a_failed_conversion_renders_null_and_is_reported_when_lenient(): void
    {
        $reported = [];
        $head = ['total' => Schema::float()->was(ApiRevision::Winter25, schema: Schema::int())];

        $projected = self::lenient($reported)->project(self::shape($head, ApiRevision::Genesis), $head, ['total' => 1.0e30]);

        self::assertSame(['total' => null], $projected);
        self::assertCount(1, $reported);
        self::assertStringEndsWith('float cannot be converted to int.', $reported[0]->getMessage());
    }

    public function test_null_on_a_non_nullable_field_is_a_value_error(): void
    {
        $reported = [];
        $head = [
            'title' => Schema::string(),
            'notes' => Schema::string()->nullable(),
            'tags' => Schema::list(Schema::string()),
            'skipped' => Schema::string()->when(static fn(): bool => false),
        ];
        $subject = ['title' => null, 'notes' => null, 'tags' => ['a', null], 'skipped' => null];

        $projected = self::lenient($reported)->project(self::shape($head, ApiRevision::Atlas), $head, $subject);

        self::assertSame(['title' => null, 'notes' => null, 'tags' => ['a', null]], $projected);
        self::assertSame(['title', '*'], array_map(static fn(ValueException $error): string => $error->field, $reported));

        $this->expectExceptionObject(ValueException::nullNotAllowed('object', 'title', ApiRevision::Atlas));

        (new Projector())->project(self::shape($head, ApiRevision::Atlas), $head, $subject);
    }

    public function test_the_exception_does_not_contain_the_value(): void
    {
        $head = ['total' => Schema::int(), 'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, schema: Schema::int())];
        $reported = [];

        self::lenient($reported)->project(self::shape($head, ApiRevision::Genesis), $head, ['total' => 'secret-1234', 'status' => 'paid']);

        self::assertCount(2, $reported);
        foreach ($reported as $error) {
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertStringNotContainsString('paid', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function test_the_value_errors_follow_the_configuration_by_default(): void
    {
        $head = ['total' => Schema::int()];

        $projected = (new Projector(new Configuration(strictValues: false)))->project(self::shape($head, ApiRevision::Atlas), $head, ['total' => 'abc']);

        self::assertSame(['total' => 0], $projected);
    }

    public function test_descriptors_without_the_was_schema_of_the_shape_are_refused(): void
    {
        $shape = self::shape(['total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string())], ApiRevision::Genesis);

        $this->expectExceptionObject(DefinitionException::missingDescriptor('object', 'total'));

        (new Projector())->project($shape, ['total' => Schema::int()->was(ApiRevision::Winter25, name: 'amount')], ['total' => 1250]);
    }

    public function test_a_composite_descriptor_on_a_scalar_shape_is_refused(): void
    {
        $shape = self::shape(['address' => Schema::string(), 'tags' => Schema::string()], ApiRevision::Atlas);

        $this->expectExceptionObject(DefinitionException::missingDescriptor('object', 'address'));

        (new Projector())->project($shape, ['address' => Schema::object([]), 'tags' => Schema::list(Schema::string())], ['address' => []]);
    }

    public function test_a_list_descriptor_on_a_scalar_shape_is_refused(): void
    {
        $shape = self::shape(['tags' => Schema::string()], ApiRevision::Atlas);

        $this->expectExceptionObject(DefinitionException::missingDescriptor('object', 'tags'));

        (new Projector())->project($shape, ['tags' => Schema::list(Schema::string())], ['tags' => []]);
    }

    public function test_nested_resources_take_their_shapes_from_the_repository_given(): void
    {
        CountingDeclaration::$described = 0;
        CountingDeclaration::$fail = false;
        $shapes = new Shapes();
        $shapes->for(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas);
        $head = ['item' => Schema::resource(CountingDeclaration::class)];

        $projected = (new Projector(shapes: $shapes))->project(self::shape($head, ApiRevision::Atlas), $head, ['item' => ['title' => 'Book']]);

        self::assertSame(['item' => ['title' => 'Book']], $projected);
        self::assertSame(2, CountingDeclaration::$described);
    }

    public function test_descriptors_missing_a_field_of_the_shape_are_refused(): void
    {
        $shape = self::shape(['title' => Schema::string(), 'total' => Schema::int()], ApiRevision::Atlas);

        $this->expectExceptionObject(DefinitionException::missingDescriptor('object', 'total'));

        (new Projector())->project($shape, ['title' => Schema::string()], ['title' => 'Book']);
    }

    /**
     * @param list<ValueException> $reported
     */
    private static function lenient(array &$reported): Projector
    {
        return new Projector(values: new LenientValues(static function (ValueException $error) use (&$reported): void {
            $reported[] = $error;
        }));
    }

    public function test_a_date_time_renders_its_day_where_it_was_a_date_without_a_closure(): void
    {
        $head = [
            'born_at' => Schema::dateTime(),
            'born_at_old' => Schema::dateTime('born_at')->was(ApiRevision::Winter25, schema: Schema::date()),
            'paid_on' => Schema::date()->was(ApiRevision::Winter25, schema: Schema::dateTime()),
        ];
        $subject = ['born_at' => new DateTimeImmutable('2026-03-04 23:30:00+02:00'), 'paid_on' => new DateTimeImmutable('2026-03-05 10:00:00+02:00')];

        self::assertSame(
            ['born_at' => '2026-03-04T23:30:00+02:00', 'born_at_old' => '2026-03-04', 'paid_on' => '2026-03-05T00:00:00+02:00'],
            self::project($head, ApiRevision::Genesis, $subject),
        );
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
