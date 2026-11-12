<?php

declare(strict_types=1);

namespace Test\Unit\Upgrade;

use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Compilation\ShapeSelector;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Upgrade\Upgrader;
use Ark4ne\ApiRevisions\Upgrade\UpgradesInput;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\OrderInputDeclaration;
use Test\Fixtures\Enums\OrderStatus;
use Test\TestCase;

final class UpgraderTest extends TestCase
{
    public function test_a_genesis_input_is_upgraded_to_the_head(): void
    {
        $upgraded = $this->upgradeOrder(ApiRevision::Genesis, ['title' => 'Book', 'state' => 'paid', 'total' => 12.5, 'name' => 'Ada Lovelace']);

        self::assertSame(
            ['title' => 'Book', 'status' => 'paid', 'total' => 1250, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'priority' => 0],
            $upgraded,
        );
    }

    /**
     * @return iterable<string, array{ApiRevision, array<string, mixed>}>
     */
    public static function inputs(): iterable
    {
        yield 'Genesis' => [ApiRevision::Genesis, ['title' => 'Book', 'state' => 'paid', 'total' => 12.5, 'name' => 'Ada Lovelace']];
        yield 'Winter25' => [ApiRevision::Winter25, ['title' => 'Book', 'state' => 'paid', 'total' => '12.50', 'name' => 'Ada Lovelace']];
        yield 'Spring26' => [ApiRevision::Spring26, ['title' => 'Book', 'status' => 'paid', 'total' => 1250, 'name' => 'Ada Lovelace']];
        yield 'Atlas' => [ApiRevision::Atlas, ['title' => 'Book', 'status' => 'paid', 'total' => 1250, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'priority' => 0]];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('inputs')]
    public function test_the_same_order_sent_at_any_revision_reaches_the_same_head_input(ApiRevision $revision, array $input): void
    {
        self::assertSame(
            ['title' => 'Book', 'status' => 'paid', 'total' => 1250, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'priority' => 0],
            $this->upgradeOrder($revision, $input),
        );
    }

    public function test_a_renamed_key_is_moved_to_its_head_name(): void
    {
        $head = ['status' => Schema::string()->was(ApiRevision::Spring26, name: 'state')->was(ApiRevision::Genesis, name: 'code')];

        self::assertSame(['status' => 'paid'], $this->upgrade($head, ApiRevision::Genesis, ['code' => 'paid']));
        self::assertSame(['status' => 'paid'], $this->upgrade($head, ApiRevision::Winter25, ['state' => 'paid']));
    }

    public function test_a_rename_chain_in_one_step_loses_nothing(): void
    {
        $head = [
            'z' => Schema::int()->was(ApiRevision::Spring26, name: 'y'),
            'y' => Schema::int()->was(ApiRevision::Spring26, name: 'x'),
        ];

        self::assertSame(['z' => 1, 'y' => 2], $this->upgrade($head, ApiRevision::Spring26, ['y' => 1, 'x' => 2]));
    }

    public function test_a_default_fills_an_absent_key_added_later(): void
    {
        $head = [
            'title' => Schema::string(),
            'priority' => Schema::int()->since(ApiRevision::Spring26, default: 3),
            'notes' => Schema::string()->since(ApiRevision::Spring26),
        ];

        self::assertSame(['title' => 'Book', 'priority' => 3], $this->upgrade($head, ApiRevision::Genesis, ['title' => 'Book']));
        self::assertSame(['priority' => 1], $this->upgrade($head, ApiRevision::Genesis, ['priority' => 1]));
        self::assertSame([], $this->upgrade($head, ApiRevision::Spring26, []));
    }

    public function test_a_default_is_serialized_by_the_head_descriptor(): void
    {
        $head = ['status' => Schema::enum(OrderStatus::class)->since(ApiRevision::Atlas, default: OrderStatus::Paid)];

        self::assertSame(['status' => 'paid'], $this->upgrade($head, ApiRevision::Genesis, []));
    }

    public function test_a_default_never_replaces_an_explicit_null(): void
    {
        $head = ['priority' => Schema::int()->nullable()->since(ApiRevision::Atlas, default: 0)];

        self::assertSame(['priority' => null], $this->upgrade($head, ApiRevision::Genesis, ['priority' => null]));
    }

    public function test_a_default_travels_through_the_later_renames_and_conversions(): void
    {
        $head = [
            'level' => Schema::int()
                ->since(ApiRevision::Winter25, default: '2')
                ->was(ApiRevision::Winter25, name: 'rank', schema: Schema::string()),
        ];

        self::assertSame(['level' => 2], $this->upgrade($head, ApiRevision::Genesis, []));
    }

    public function test_a_default_of_a_field_retired_before_the_head_never_reaches_it(): void
    {
        $head = ['legacy' => Schema::string()->since(ApiRevision::Winter25, default: 'x')->until(ApiRevision::Spring26)];

        self::assertSame([], $this->upgrade($head, ApiRevision::Genesis, []));
    }

    public function test_an_absent_key_is_not_converted(): void
    {
        $called = false;
        $head = [
            'total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string(), up: static function () use (&$called): int {
                $called = true;

                return 0;
            }),
        ];

        self::assertSame([], $this->upgrade($head, ApiRevision::Genesis, []));
        self::assertFalse($called);
    }

    public function test_an_explicit_null_is_renamed_and_never_converted(): void
    {
        $head = [
            'total' => Schema::int()->nullable()->was(ApiRevision::Winter25, name: 'amount', schema: Schema::string()->nullable(), up: static fn(string $amount): int => (int) $amount),
        ];

        self::assertSame(['total' => null], $this->upgrade($head, ApiRevision::Genesis, ['amount' => null]));
    }

    public function test_an_up_closure_receives_the_value_normalized_to_its_step_and_the_snapshot_of_the_step(): void
    {
        $received = [];
        $head = [
            'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
            'count' => Schema::int()->was(ApiRevision::Winter25, name: 'qty', up: static function (int $count, array $snapshot) use (&$received): int {
                $received = [$count, $snapshot];

                return $count * 2;
            }),
        ];

        $upgraded = $this->upgrade($head, ApiRevision::Genesis, ['state' => 'paid', 'qty' => '5']);

        self::assertSame(['status' => 'paid', 'count' => 10], $upgraded);
        self::assertSame([5, ['state' => 'paid', 'qty' => '5']], $received);
    }

    public function test_a_registered_conversion_upgrades_a_value_between_two_types(): void
    {
        $head = ['total' => Schema::float()->was(ApiRevision::Spring26, schema: Schema::int())];

        self::assertSame(['total' => 12.0], $this->upgrade($head, ApiRevision::Genesis, ['total' => '12']));
    }

    public function test_a_converted_value_is_serialized_back_by_the_head_descriptor(): void
    {
        $configuration = new Configuration(dateFormat: 'd/m/Y');
        $head = [
            'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Spring26, schema: Schema::string(), up: static fn(string $state): OrderStatus => OrderStatus::from($state)),
            'day' => Schema::date()->was(ApiRevision::Spring26, schema: Schema::string(), up: static fn(string $day): DateTimeImmutable => new DateTimeImmutable($day)),
        ];

        self::assertSame(
            ['status' => 'paid', 'day' => '04/03/2026'],
            $this->upgrade($head, ApiRevision::Genesis, ['status' => 'paid', 'day' => '2026-03-04'], configuration: $configuration),
        );
    }

    public function test_a_field_retired_is_dropped_after_the_hook_of_its_last_step(): void
    {
        $hook = new class implements UpgradesInput {
            /**
             * @var list<array<string, mixed>>
             */
            public array $seen = [];

            public function upgrade(Revision $to, array $input): array
            {
                $this->seen[] = $input;

                return $input;
            }
        };
        $head = [
            'title' => Schema::string(),
            'name' => Schema::string()->until(ApiRevision::Winter25),
        ];

        self::assertSame(['title' => 'Book'], $this->upgrade($head, ApiRevision::Genesis, ['title' => 'Book', 'name' => 'Ada'], $hook));
        self::assertSame([['title' => 'Book', 'name' => 'Ada'], ['title' => 'Book', 'name' => 'Ada'], ['title' => 'Book']], $hook->seen);
    }

    public function test_the_hook_runs_after_the_renames_and_before_the_defaults_of_its_step(): void
    {
        $hook = new class implements UpgradesInput {
            public function upgrade(Revision $to, array $input): array
            {
                return $to === ApiRevision::Winter25 ? [...$input, 'seen' => array_keys($input)] : $input;
            }
        };
        $head = [
            'status' => Schema::string()->was(ApiRevision::Genesis, name: 'state'),
            'seen' => Schema::rule(['array']),
            'priority' => Schema::int()->since(ApiRevision::Winter25, default: 0),
        ];

        self::assertSame(['status' => 'paid', 'seen' => ['status'], 'priority' => 0], $this->upgrade($head, ApiRevision::Genesis, ['state' => 'paid'], $hook));
    }

    public function test_the_hook_is_called_once_per_step_with_its_target(): void
    {
        $hook = new class implements UpgradesInput {
            /**
             * @var list<Revision>
             */
            public array $targets = [];

            public function upgrade(Revision $to, array $input): array
            {
                $this->targets[] = $to;

                return $input;
            }
        };
        $head = ['title' => Schema::string()];

        $this->upgrade($head, ApiRevision::Genesis, ['title' => 'Book'], $hook);
        $this->upgrade($head, ApiRevision::Atlas, ['title' => 'Book'], $hook);

        self::assertSame([ApiRevision::Winter25, ApiRevision::Spring26, ApiRevision::Atlas], $hook->targets);
    }

    public function test_the_hook_sees_the_values_converted_by_its_step(): void
    {
        $hook = new class implements UpgradesInput {
            public mixed $total = null;

            public function upgrade(Revision $to, array $input): array
            {
                if ($to === ApiRevision::Winter25) {
                    $this->total = $input['total'];
                }

                return $input;
            }
        };
        $head = ['total' => Schema::float()->was(ApiRevision::Genesis, schema: Schema::int(), up: static fn(int $cents): float => $cents / 100)];

        self::assertSame(['total' => 12.5], $this->upgrade($head, ApiRevision::Genesis, ['total' => 1250], $hook));
        self::assertSame(12.5, $hook->total);
    }

    public function test_the_hook_sees_the_names_of_its_step(): void
    {
        $hook = new class implements UpgradesInput {
            /**
             * @var array<string, list<string>>
             */
            public array $seen = [];

            public function upgrade(Revision $to, array $input): array
            {
                $this->seen[$to->name] = array_keys($input);

                return $input;
            }
        };
        $head = [
            'status' => Schema::string()->was(ApiRevision::Spring26, name: 'state')->was(ApiRevision::Winter25, name: 'phase')->was(ApiRevision::Genesis, name: 'code'),
            'name' => Schema::string()->was(ApiRevision::Winter25, name: 'label')->until(ApiRevision::Spring26),
        ];

        self::assertSame(['status' => 'paid'], $this->upgrade($head, ApiRevision::Genesis, ['code' => 'paid', 'label' => 'Ada'], $hook));
        self::assertSame(['Winter25' => ['label', 'phase'], 'Spring26' => ['state', 'name'], 'Atlas' => ['status', 'name']], $hook->seen);
    }

    public function test_a_value_never_converted_is_kept_as_sent(): void
    {
        $head = ['meta' => Schema::object(['a' => Schema::int()])->was(ApiRevision::Genesis, name: 'data')];

        self::assertSame(['meta' => []], $this->upgrade($head, ApiRevision::Genesis, ['data' => []]));
    }

    public function test_the_converters_given_replace_the_registered_ones(): void
    {
        $head = ['total' => Schema::float()->was(ApiRevision::Spring26, schema: Schema::int())];

        $this->expectException(ConversionException::class);

        (new Upgrader(converters: new Converters()))->upgrade(ShapeSelector::at($head, ApiRevision::Genesis, Direction::Up), $head, ['total' => 12]);
    }

    public function test_a_field_renamed_onto_the_name_of_a_retired_field_keeps_its_value(): void
    {
        $head = [
            'label' => Schema::string()->until(ApiRevision::Genesis),
            'title' => Schema::string()->was(ApiRevision::Winter25, name: 'label')->was(ApiRevision::Genesis, name: 'caption'),
        ];

        self::assertSame(['title' => 'v'], $this->upgrade($head, ApiRevision::Genesis, ['label' => 'old', 'caption' => 'v']));
    }

    public function test_a_default_reusing_the_name_of_a_retired_field_is_written(): void
    {
        $head = [
            'label' => Schema::string()->until(ApiRevision::Genesis),
            'title' => Schema::string()->since(ApiRevision::Winter25, default: 'd')->was(ApiRevision::Winter25, name: 'label'),
        ];

        self::assertSame(['title' => 'd'], $this->upgrade($head, ApiRevision::Genesis, ['label' => 'old']));
    }

    public function test_a_response_shape_cannot_be_upgraded(): void
    {
        $head = ['title' => Schema::string()];

        $this->expectExceptionObject(DefinitionException::notAnUpShape('object'));

        (new Upgrader())->upgrade(ShapeSelector::at($head, ApiRevision::Genesis, Direction::Down), $head, []);
    }

    public function test_a_key_the_shape_does_not_know_is_kept(): void
    {
        self::assertSame(['title' => 'Book', 'extra' => 1], $this->upgrade(['title' => Schema::string()], ApiRevision::Genesis, ['title' => 'Book', 'extra' => 1]));
    }

    public function test_a_value_its_type_refuses_at_a_step_throws(): void
    {
        $head = ['count' => Schema::int()->was(ApiRevision::Winter25, up: static fn(int $count): int => $count)];

        $this->expectExceptionObject(ValueException::refused('object', 'count', ApiRevision::Winter25, 'int', 'string'));

        $this->upgrade($head, ApiRevision::Genesis, ['count' => 'many']);
    }

    public function test_a_step_without_converter_throws(): void
    {
        $head = ['total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::list(Schema::int()))];

        $this->expectException(ConversionException::class);

        $this->upgrade($head, ApiRevision::Genesis, ['total' => [1]]);
    }

    public function test_descriptors_missing_a_field_of_the_shape_throw(): void
    {
        $shape = (new Shapes())->for(OrderInputDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);

        $this->expectExceptionObject(DefinitionException::missingDescriptor(OrderInputDeclaration::class, 'title'));

        (new Upgrader())->upgrade($shape, [], []);
    }

    public function test_descriptors_missing_a_field_of_a_shape_without_class_throw(): void
    {
        $shape = ShapeSelector::at(['title' => Schema::string()], ApiRevision::Genesis, Direction::Up);

        $this->expectExceptionObject(DefinitionException::missingDescriptor('object', 'title'));

        (new Upgrader())->upgrade($shape, [], ['title' => 'Book']);
    }

    public function test_descriptors_without_the_schema_of_a_was_of_the_shape_throw(): void
    {
        $shape = ShapeSelector::at(['count' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string())], ApiRevision::Genesis, Direction::Up);

        $this->expectExceptionObject(DefinitionException::missingDescriptor('object', 'count'));

        (new Upgrader())->upgrade($shape, ['count' => Schema::int()->was(ApiRevision::Winter25, name: 'n')], ['count' => '3']);
    }

    public function test_a_value_projected_down_then_upgraded_comes_back_to_the_head(): void
    {
        $head = [
            'total' => Schema::int()
                ->was(
                    ApiRevision::Winter25,
                    schema: Schema::string(),
                    down: static fn(int $cents): string => number_format($cents / 100, 2, '.', ''),
                    up: static fn(string $amount): int => (int) round(((float) $amount) * 100),
                )
                ->was(ApiRevision::Genesis, schema: Schema::float()),
        ];
        foreach (ApiRevision::cases() as $revision) {
            $projected = (new Projector())->project(ShapeSelector::at($head, $revision, Direction::Down), $head, ['total' => 1250]);

            self::assertSame(['total' => 1250], $this->upgrade($head, $revision, $projected), $revision->name);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function upgradeOrder(ApiRevision $revision, array $input): array
    {
        $shape = (new Shapes())->for(OrderInputDeclaration::class, DeclarationKind::RequestBody, $revision);

        return (new Upgrader())->upgrade(
            $shape,
            OrderInputDeclaration::describeRevisioned(DeclarationKind::RequestBody, null),
            $input,
            new OrderInputDeclaration(),
        );
    }

    /**
     * @param array<string, Descriptor<mixed>> $head
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function upgrade(array $head, Revision $revision, array $input, ?UpgradesInput $hook = null, Configuration $configuration = new Configuration()): array
    {
        return (new Upgrader($configuration))->upgrade(ShapeSelector::at($head, $revision, Direction::Up), $head, $input, $hook);
    }
}
