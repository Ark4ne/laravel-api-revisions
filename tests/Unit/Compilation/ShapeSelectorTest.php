<?php

declare(strict_types=1);

namespace Test\Unit\Compilation;

use Ark4ne\ApiRevisions\Compilation\Collision;
use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\FieldVersion;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\ShapeSelector;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Configuration\MissingDefault;
use Ark4ne\ApiRevisions\Conversion\ConversionStep;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Conversion\StepKind;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Support\Undefined;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Revisions\IntRevision;
use Test\TestCase;

final class ShapeSelectorTest extends TestCase
{
    /**
     * The order of the reference scenario.
     *
     * @return array<string, Descriptor<mixed>>
     */
    private static function order(): array
    {
        return [
            'title' => Schema::string(),
            'status' => Schema::enum(OrderStatus::class)
                ->was(ApiRevision::Winter25, name: 'state'),
            'total' => Schema::int()
                ->was(
                    ApiRevision::Winter25,
                    schema: Schema::string(),
                    down: static fn(int $cents): string => number_format($cents / 100, 2, '.', ''),
                    up: static fn(string $amount): int => (int) round(((float) $amount) * 100),
                )
                ->was(ApiRevision::Genesis, schema: Schema::float()),
            ...Schema::since(ApiRevision::Atlas, [
                'tags' => Schema::list(Schema::string()),
                'notes' => Schema::string()->nullable(),
            ]),
            ...Schema::until(ApiRevision::Spring26, [
                'name' => Schema::string(static fn(): string => 'Ada Lovelace'),
            ]),
        ];
    }

    /**
     * @return iterable<string, array{Revision, list<string>, Kind}>
     */
    public static function referenceRows(): iterable
    {
        yield 'Atlas' => [ApiRevision::Atlas, ['title', 'status', 'total', 'tags', 'notes'], Kind::Int];
        yield 'Spring26' => [ApiRevision::Spring26, ['title', 'status', 'total', 'name'], Kind::Int];
        yield 'Winter25' => [ApiRevision::Winter25, ['title', 'state', 'total', 'name'], Kind::String];
        yield 'Genesis' => [ApiRevision::Genesis, ['title', 'state', 'total', 'name'], Kind::Float];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('referenceRows')]
    public function test_each_row_of_the_reference_table_is_selected(Revision $revision, array $names, Kind $total): void
    {
        $shape = ShapeSelector::at(self::order(), $revision, Direction::Down);

        self::assertSame($names, $shape->names());
        self::assertSame($total, $shape->fields['total']->type->kind);
        self::assertSame($revision, $shape->revision);
        self::assertSame(Direction::Down, $shape->direction);
    }

    public function test_the_head_shape_ignores_every_was(): void
    {
        $shape = ShapeSelector::at(self::order(), ApiRevision::Atlas, Direction::Down);

        foreach ($shape->fields as $key => $field) {
            self::assertSame($key, $field->headKey);
            self::assertSame($key, $field->name);
            self::assertSame([], $field->versions);
            self::assertTrue($field->chain->isEmpty());
            self::assertNull($field->schemaChange);
        }
        self::assertEquals(self::order()['total']->toType(), $shape->fields['total']->type);
    }

    public function test_a_rename_applies_up_to_its_revision_included(): void
    {
        $name = static fn(Revision $revision): string => ShapeSelector::at(self::order(), $revision, Direction::Down)
            ->fields['status']->name;

        self::assertSame('status', $name(ApiRevision::Spring26));
        self::assertSame('state', $name(ApiRevision::Winter25));
        self::assertSame('state', $name(ApiRevision::Genesis));
    }

    public function test_an_omitted_name_is_inherited_from_the_next_newer_shape(): void
    {
        $head = [
            'status' => Schema::enum(OrderStatus::class)
                ->was(ApiRevision::Winter25, name: 'state')
                ->was(ApiRevision::Genesis, schema: Schema::string()),
        ];

        $field = ShapeSelector::at($head, ApiRevision::Genesis, Direction::Down)->fields['status'];

        self::assertSame('state', $field->name);
        self::assertSame(Kind::String, $field->type->kind);
    }

    public function test_an_omitted_schema_is_inherited_from_the_next_newer_shape(): void
    {
        $head = [
            'total' => Schema::int()
                ->was(ApiRevision::Spring26, schema: Schema::string())
                ->was(ApiRevision::Genesis, name: 'amount'),
        ];

        $field = ShapeSelector::at($head, ApiRevision::Genesis, Direction::Down)->fields['total'];

        self::assertSame('amount', $field->name);
        self::assertSame(Kind::String, $field->type->kind);
        self::assertSame(0, $field->schemaChange);
    }

    public function test_chained_type_changes_give_the_type_of_the_oldest_applicable_was(): void
    {
        $type = static fn(Revision $revision): Kind => ShapeSelector::at(self::order(), $revision, Direction::Down)
            ->fields['total']->type->kind;

        self::assertSame(Kind::Int, $type(ApiRevision::Atlas));
        self::assertSame(Kind::Int, $type(ApiRevision::Spring26));
        self::assertSame(Kind::String, $type(ApiRevision::Winter25));
        self::assertSame(Kind::Float, $type(ApiRevision::Genesis));
    }

    public function test_a_field_until_a_revision_is_absent_from_the_next_one(): void
    {
        self::assertNotNull(ShapeSelector::at(self::order(), ApiRevision::Spring26, Direction::Down)->field('name'));
        self::assertNull(ShapeSelector::at(self::order(), ApiRevision::Atlas, Direction::Down)->field('name'));
    }

    public function test_a_field_since_a_revision_is_absent_from_the_previous_one(): void
    {
        self::assertNotNull(ShapeSelector::at(self::order(), ApiRevision::Atlas, Direction::Down)->field('tags'));
        self::assertNull(ShapeSelector::at(self::order(), ApiRevision::Spring26, Direction::Down)->field('tags'));
    }

    public function test_versions_record_the_shape_at_every_applicable_was_newest_first(): void
    {
        $field = ShapeSelector::at(self::order(), ApiRevision::Genesis, Direction::Down)->fields['total'];

        self::assertEquals([
            new FieldVersion(ApiRevision::Winter25, 0, 'total', Schema::string()->toType(), 0),
            new FieldVersion(ApiRevision::Genesis, 1, 'total', Schema::float()->toType(), 1),
        ], $field->versions);
        self::assertSame(1, $field->schemaChange);
    }

    public function test_a_version_without_schema_keeps_the_schema_in_force_from_a_newer_was(): void
    {
        $head = ['total' => Schema::int()->was(ApiRevision::Spring26, schema: Schema::string())->was(ApiRevision::Winter25, name: 'amount')];

        $versions = ShapeSelector::at($head, ApiRevision::Genesis, Direction::Up)->fields['total']->versions;

        self::assertSame([0, 0], array_map(static fn(FieldVersion $version): ?int => $version->schemaChange, $versions));
        self::assertNull(ShapeSelector::at(['a' => Schema::int()->was(ApiRevision::Winter25, name: 'b')], ApiRevision::Genesis, Direction::Up)->fields['a']->versions[0]->schemaChange);
    }

    public function test_versions_stop_at_the_revision(): void
    {
        $field = ShapeSelector::at(self::order(), ApiRevision::Winter25, Direction::Down)->fields['status'];

        self::assertEquals(
            [new FieldVersion(ApiRevision::Winter25, 0, 'state', Schema::enum(OrderStatus::class)->toType())],
            $field->versions,
        );
    }

    public function test_the_down_chain_runs_from_the_head_to_the_revision(): void
    {
        $chain = ShapeSelector::at(self::order(), ApiRevision::Genesis, Direction::Down)->fields['total']->chain;

        self::assertSame(Direction::Down, $chain->direction);
        self::assertSame(
            [[StepKind::Explicit, 0, Kind::Int, Kind::String], [StepKind::Registered, 1, Kind::String, Kind::Float]],
            self::steps($chain->steps),
        );
    }

    public function test_the_up_chain_runs_from_the_revision_to_the_head(): void
    {
        $chain = ShapeSelector::at(self::order(), ApiRevision::Genesis, Direction::Up)->fields['total']->chain;

        self::assertSame(Direction::Up, $chain->direction);
        self::assertSame(
            [[StepKind::Registered, 1, Kind::Float, Kind::String], [StepKind::Explicit, 0, Kind::String, Kind::Int]],
            self::steps($chain->steps),
        );
    }

    public function test_a_shape_plans_only_its_direction(): void
    {
        $head = ['total' => Schema::int()->was(ApiRevision::Spring26, schema: Schema::string(), up: static fn(string $s): int => (int) $s)];

        $down = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Down)->fields['total']->chain;

        self::assertSame([[StepKind::Registered, 0, Kind::Int, Kind::String]], self::steps($down->steps));
    }

    public function test_a_rename_adds_no_conversion_step(): void
    {
        self::assertTrue(ShapeSelector::at(self::order(), ApiRevision::Genesis, Direction::Down)->fields['status']->chain->isEmpty());
        self::assertTrue(ShapeSelector::at(self::order(), ApiRevision::Genesis, Direction::Up)->fields['status']->chain->isEmpty());
    }

    public function test_a_conversion_without_converter_is_planned_missing_not_thrown(): void
    {
        $head = ['tags' => Schema::list(Schema::string())->was(ApiRevision::Spring26, schema: Schema::string())];

        $chain = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Down)->fields['tags']->chain;

        self::assertSame([[StepKind::Missing, 0, Kind::List, Kind::String]], self::steps($chain->steps));
    }

    public function test_the_chains_are_planned_against_the_given_converters(): void
    {
        $head = ['total' => Schema::int()->was(ApiRevision::Spring26, schema: Schema::string())];

        $chain = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Down, new Converters())->fields['total']->chain;

        self::assertSame([[StepKind::Missing, 0, Kind::Int, Kind::String]], self::steps($chain->steps));
    }

    public function test_the_schema_of_a_was_gives_the_value_and_the_head_the_presence(): void
    {
        $head = [
            'total' => Schema::int()->required()->sometimes()->when(static fn(): bool => true)
                ->since(ApiRevision::Winter25, default: 0)
                ->was(ApiRevision::Winter25, schema: Schema::string()->nullable()->required(false)->sometimes(false)),
        ];

        $old = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Down)->fields['total'];
        $new = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Down)->fields['total'];

        self::assertTrue($old->nullable);
        self::assertFalse($new->nullable);
        foreach ([$old, $new] as $field) {
            self::assertTrue($field->required);
            self::assertTrue($field->sometimes);
            self::assertTrue($field->conditional);
            self::assertSame(0, $field->default);
            self::assertSame(ApiRevision::Winter25, $field->lifetime->since);
        }
        self::assertSame(0, $old->schemaChange);
        self::assertNull($new->schemaChange);
    }

    public function test_a_presence_condition_makes_a_field_conditional(): void
    {
        $head = ['notes' => Schema::string()->whenHas(), 'title' => Schema::string()];

        $shape = ShapeSelector::at($head, ApiRevision::Atlas, Direction::Down);

        self::assertTrue($shape->fields['notes']->conditional);
        self::assertFalse($shape->fields['title']->conditional);
        self::assertFalse($shape->fields['title']->required);
        self::assertFalse($shape->fields['title']->sometimes);
        self::assertTrue(Undefined::is($shape->fields['title']->default));
    }

    public function test_nested_children_follow_their_own_history(): void
    {
        $head = [
            'address' => Schema::object([
                'street' => Schema::string()->was(ApiRevision::Winter25, name: 'line1'),
                'zip' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string()),
                'country' => Schema::string()->since(ApiRevision::Atlas),
            ]),
        ];

        $address = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Down)->fields['address'];

        self::assertNotNull($address->children);
        self::assertSame(['line1', 'zip'], $address->children->names());
        self::assertSame(ApiRevision::Winter25, $address->children->revision);
        self::assertSame(Direction::Down, $address->children->direction);
        self::assertSame(
            [[StepKind::Registered, 0, Kind::Int, Kind::String]],
            self::steps($address->children->fields['zip']->chain->steps),
        );
        self::assertSame(['line1', 'zip'], array_keys($address->type->children));
        self::assertSame(Kind::String, $address->type->children['zip']->kind);
        self::assertTrue($address->chain->isEmpty());
    }

    public function test_a_scalar_has_neither_children_nor_items(): void
    {
        $field = ShapeSelector::at(self::order(), ApiRevision::Atlas, Direction::Down)->fields['title'];

        self::assertNull($field->children);
        self::assertNull($field->items);
    }

    public function test_the_items_of_a_list_follow_their_own_history(): void
    {
        $head = ['codes' => Schema::list(Schema::int()->was(ApiRevision::Winter25, schema: Schema::string()))->min(1)];

        $codes = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Up)->fields['codes'];

        self::assertNotNull($codes->items);
        self::assertSame('*', $codes->items->headKey);
        self::assertSame('*', $codes->items->name);
        self::assertSame(Kind::String, $codes->items->type->kind);
        self::assertSame(
            [[StepKind::Registered, 0, Kind::String, Kind::Int]],
            self::steps($codes->items->chain->steps),
        );
        self::assertSame(Kind::String, $codes->type->items?->kind);
        self::assertSame(1, $codes->type->min);
        self::assertTrue($codes->chain->isEmpty());
    }

    public function test_the_schema_of_a_was_replaces_the_type_of_the_children_not_their_shape(): void
    {
        $head = [
            'address' => Schema::object([
                'street' => Schema::string()->was(ApiRevision::Genesis, name: 'line1'),
            ])->was(ApiRevision::Winter25, schema: Schema::object(['line' => Schema::string()]), down: static fn(array $a): array => $a),
        ];

        $address = ShapeSelector::at($head, ApiRevision::Genesis, Direction::Down)->fields['address'];

        self::assertNotNull($address->children);
        self::assertSame(['street'], $address->children->names());
        self::assertSame(ApiRevision::Spring26, $address->children->revision);
        self::assertSame(ApiRevision::Spring26, $address->headAt);
        self::assertSame(['line'], array_keys($address->type->children));
        self::assertSame(ApiRevision::Genesis, ShapeSelector::at($head, ApiRevision::Genesis, Direction::Down)->fields['address']->revision);
        self::assertNull(ShapeSelector::at(['total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::list(Schema::int()))], ApiRevision::Genesis, Direction::Down)->fields['total']->items);
    }

    public function test_the_head_children_are_read_after_the_newest_schema_of_a_was(): void
    {
        $head = [
            'address' => Schema::object([
                'street' => Schema::string()->was(ApiRevision::Winter25, name: 'line1'),
            ])
                ->was(ApiRevision::Spring26, schema: Schema::object(['line' => Schema::string()]), down: static fn(array $a): array => $a)
                ->was(ApiRevision::Genesis, schema: Schema::string(), down: static fn(): string => ''),
        ];

        $address = ShapeSelector::at($head, ApiRevision::Genesis, Direction::Down)->fields['address'];

        self::assertSame(ApiRevision::Atlas, $address->headAt);
        self::assertSame(['street'], $address->children?->names());
    }

    public function test_a_step_crosses_the_types_of_both_shapes_at_their_revisions(): void
    {
        $head = [
            'address' => Schema::object([
                'street' => Schema::string(),
                'country' => Schema::string()->since(ApiRevision::Atlas),
            ])->was(ApiRevision::Spring26, schema: Schema::string(), down: static fn(array $a): string => 'Paris'),
        ];

        $down = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Down)->fields['address']->chain->steps;
        $up = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Up)->fields['address']->chain->steps;

        self::assertSame(['street', 'country'], array_keys($down[0]->from->children));
        self::assertSame(Kind::String, $down[0]->to->kind);
        self::assertSame(Kind::String, $up[0]->from->kind);
        self::assertSame(['street', 'country'], array_keys($up[0]->to->children));
        self::assertSame(StepKind::Missing, $up[0]->kind);
    }

    public function test_a_renamed_object_whose_children_change_plans_no_step(): void
    {
        $head = [
            'address' => Schema::object([
                'zip' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string()),
                'country' => Schema::string()->since(ApiRevision::Atlas),
            ])->was(ApiRevision::Spring26, name: 'addr'),
        ];

        foreach ([Direction::Down, Direction::Up] as $direction) {
            $address = ShapeSelector::at($head, ApiRevision::Genesis, $direction)->fields['address'];

            self::assertSame('addr', $address->name);
            self::assertTrue($address->chain->isEmpty());
        }
    }

    public function test_a_renamed_list_whose_items_change_plans_no_step(): void
    {
        $head = ['codes' => Schema::list(Schema::int()->was(ApiRevision::Winter25, schema: Schema::string()))->was(ApiRevision::Winter25, name: 'refs')];

        $codes = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Down)->fields['codes'];

        self::assertTrue($codes->chain->isEmpty());
        self::assertSame([[StepKind::Registered, 0, Kind::Int, Kind::String]], self::steps($codes->items?->chain->steps ?? []));
    }

    public function test_a_was_without_schema_converts_through_its_explicit_converter_only(): void
    {
        $head = ['code' => Schema::string()->was(ApiRevision::Winter25, down: static fn(string $code): string => strtolower($code))];

        $down = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Down)->fields['code']->chain;
        $up = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Up)->fields['code']->chain;

        self::assertSame([[StepKind::Explicit, 0, Kind::String, Kind::String]], self::steps($down->steps));
        self::assertTrue($up->isEmpty());
    }

    public function test_a_name_collision_is_recorded_on_the_shape(): void
    {
        $head = [
            'status' => Schema::string()->was(ApiRevision::Winter25, name: 'state'),
            'title' => Schema::string(),
            'state' => Schema::string()->until(ApiRevision::Winter25),
        ];

        $old = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Down);
        $new = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Down);

        self::assertEquals([new Collision('state', ['status', 'state'])], $old->collisions);
        self::assertSame(['state', 'title', 'state'], $old->names());
        self::assertSame([], $new->collisions);
    }

    public function test_a_collision_between_children_is_recorded_on_their_shape(): void
    {
        $head = [
            'address' => Schema::object([
                'line1' => Schema::string(),
                'street' => Schema::int()->was(ApiRevision::Genesis, name: 'line1'),
            ]),
        ];

        $shape = ShapeSelector::at($head, ApiRevision::Genesis, Direction::Down);

        self::assertSame([], $shape->collisions);
        self::assertEquals([new Collision('line1', ['line1', 'street'])], $shape->fields['address']->children?->collisions);
        self::assertSame(['line1'], array_keys($shape->fields['address']->type->children));
        self::assertSame(Kind::String, $shape->fields['address']->type->children['line1']->kind);
    }

    public function test_the_up_shape_holds_the_fields_added_later_at_their_since(): void
    {
        $head = [
            'title' => Schema::string(),
            ...Schema::since(ApiRevision::Atlas, [
                'first_name' => Schema::string()->required(),
                'priority' => Schema::int()->required()->since(ApiRevision::Atlas, default: 0),
            ], default: null),
            'code' => Schema::int()
                ->since(ApiRevision::Winter25, default: 7)
                ->was(ApiRevision::Spring26, name: 'ref', schema: Schema::string()),
            'old' => Schema::string()->since(ApiRevision::Winter25, default: 'x')->until(ApiRevision::Spring26),
            'gone' => Schema::string()->until(ApiRevision::Genesis),
        ];

        $shape = ShapeSelector::at($head, ApiRevision::Genesis, Direction::Up);

        self::assertSame(['title', 'gone'], array_keys($shape->fields));
        self::assertSame(['first_name', 'priority', 'code', 'old'], array_keys($shape->added));
        self::assertNull($shape->added['first_name']->default);
        self::assertSame(0, $shape->added['priority']->default);
        self::assertSame(ApiRevision::Atlas, $shape->added['priority']->revision);

        $code = $shape->added['code'];
        self::assertSame(ApiRevision::Winter25, $code->revision);
        self::assertSame('ref', $code->name);
        self::assertSame(Kind::String, $code->type->kind);
        self::assertSame(7, $code->default);
        self::assertSame([[StepKind::Registered, 0, Kind::String, Kind::Int]], self::steps($code->chain->steps));

        self::assertSame(ApiRevision::Spring26, $shape->added['old']->lifetime->until);
    }

    public function test_undefined_and_null_defaults_are_distinct(): void
    {
        $head = [
            'nickname' => Schema::string()->required()->since(ApiRevision::Atlas, default: null),
            'surname' => Schema::string()->required()->since(ApiRevision::Atlas),
        ];

        $added = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Up)->added;

        self::assertNull($added['nickname']->default);
        self::assertTrue(Undefined::is($added['surname']->default));
    }

    public function test_a_field_since_a_revision_has_its_default_in_every_older_shape(): void
    {
        $head = ['priority' => Schema::int()->since(ApiRevision::Spring26, default: 0)];

        foreach ([ApiRevision::Winter25, ApiRevision::Genesis] as $revision) {
            $priority = ShapeSelector::at($head, $revision, Direction::Up)->added['priority'];

            self::assertSame(0, $priority->default);
            self::assertSame(ApiRevision::Spring26, $priority->revision);
        }
    }

    public function test_missing_default_null_option_fills_null(): void
    {
        $head = [
            'surname' => Schema::string()->required()->since(ApiRevision::Atlas),
            'address' => Schema::object(['country' => Schema::string()->required()->since(ApiRevision::Atlas)]),
            'codes' => Schema::list(Schema::object(['kind' => Schema::string()->required()->since(ApiRevision::Atlas)])),
        ];
        $configuration = new Configuration(missingDefault: MissingDefault::Null);

        $shape = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Up, configuration: $configuration);

        self::assertNull($shape->added['surname']->default);
        self::assertNull($shape->fields['address']->children?->added['country']->default);
        self::assertNull($shape->fields['codes']->items?->children?->added['kind']->default);
    }

    public function test_missing_default_null_option_leaves_optional_fields_and_given_defaults(): void
    {
        $head = [
            'nickname' => Schema::string()->since(ApiRevision::Atlas),
            'priority' => Schema::int()->required()->since(ApiRevision::Atlas, default: 0),
            'title' => Schema::string()->required(),
        ];
        $configuration = new Configuration(missingDefault: MissingDefault::Null);

        $shape = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Up, configuration: $configuration);

        self::assertTrue(Undefined::is($shape->added['nickname']->default));
        self::assertSame(0, $shape->added['priority']->default);
        self::assertTrue(Undefined::is($shape->fields['title']->default));
    }

    public function test_missing_default_error_option_leaves_the_default_undefined(): void
    {
        $head = ['surname' => Schema::string()->required()->since(ApiRevision::Atlas)];

        $shape = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Up, configuration: new Configuration());

        self::assertTrue(Undefined::is($shape->added['surname']->default));
    }

    public function test_a_field_added_at_the_revision_is_visible_not_added(): void
    {
        $head = ['tags' => Schema::list(Schema::string())->since(ApiRevision::Winter25, default: [])];

        $shape = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Up);

        self::assertSame(['tags'], array_keys($shape->fields));
        self::assertSame([], $shape->added);
    }

    public function test_the_down_shape_has_no_added_field(): void
    {
        $shape = ShapeSelector::at(self::order(), ApiRevision::Genesis, Direction::Down);

        self::assertSame([], $shape->added);
    }

    public function test_a_child_added_later_is_added_in_the_up_shape_of_its_object(): void
    {
        $head = ['address' => Schema::object(['street' => Schema::string(), 'country' => Schema::string()->since(ApiRevision::Atlas, default: 'FR')])];

        $children = ShapeSelector::at($head, ApiRevision::Spring26, Direction::Up)->fields['address']->children;

        self::assertNotNull($children);
        self::assertSame(['street'], $children->names());
        self::assertSame(['country'], array_keys($children->added));
        self::assertSame('FR', $children->added['country']->default);
    }

    public function test_a_was_newer_than_the_until_still_applies(): void
    {
        $head = ['name' => Schema::string()->until(ApiRevision::Winter25)->was(ApiRevision::Spring26, name: 'label')];

        $field = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Down)->fields['name'];

        self::assertSame('label', $field->name);
    }

    public function test_selecting_runs_no_source_condition_nor_converter(): void
    {
        $fail = static fn(): never => throw new LogicException('ran');
        $head = [
            'total' => Schema::int($fail)->when($fail)
                ->was(ApiRevision::Winter25, schema: Schema::string(), down: $fail, up: $fail),
        ];

        $shape = ShapeSelector::at($head, ApiRevision::Genesis, Direction::Down);

        self::assertSame(['total'], $shape->names());
    }

    public function test_the_shape_serializes_without_closure(): void
    {
        $shape = ShapeSelector::at(self::order(), ApiRevision::Genesis, Direction::Down);

        $copy = unserialize(serialize($shape));

        self::assertInstanceOf(Shape::class, $copy);
        self::assertEquals($shape, $copy);
        self::assertSame(ApiRevision::Genesis, $copy->revision);
    }

    public function test_an_int_backed_revision_enum_is_compared_by_its_order(): void
    {
        $head = [
            'amount' => Schema::int()->was(IntRevision::Second, schema: Schema::string()),
            'extra' => Schema::string()->since(IntRevision::Third),
        ];

        self::assertSame(['amount'], ShapeSelector::at($head, IntRevision::Second, Direction::Down)->names());
        self::assertSame(Kind::String, ShapeSelector::at($head, IntRevision::First, Direction::Down)->fields['amount']->type->kind);
        self::assertSame(Kind::Int, ShapeSelector::at($head, IntRevision::Third, Direction::Down)->fields['amount']->type->kind);
    }

    public function test_the_type_at_the_revision_keeps_its_documentation(): void
    {
        $head = ['total' => Schema::int()->describe('In cents')->was(ApiRevision::Winter25, schema: Schema::string()->describe('Decimal'))];

        self::assertSame('Decimal', ShapeSelector::at($head, ApiRevision::Winter25, Direction::Down)->fields['total']->type->description);
        self::assertSame('In cents', ShapeSelector::at($head, ApiRevision::Spring26, Direction::Down)->fields['total']->type->description);
    }

    public function test_every_field_definition_names_its_revision(): void
    {
        $shape = ShapeSelector::at(self::order(), ApiRevision::Winter25, Direction::Down);

        self::assertSame([ApiRevision::Winter25], array_values(array_unique(array_map(
            static fn(FieldDefinition $field): Revision => $field->revision,
            array_values($shape->fields),
        ), SORT_REGULAR)));
    }

    /**
     * @param list<ConversionStep> $steps
     *
     * @return list<array{StepKind, int, Kind, Kind}>
     */
    private static function steps(array $steps): array
    {
        return array_map(
            static fn(ConversionStep $step): array => [$step->kind, $step->change, $step->from->kind, $step->to->kind],
            $steps,
        );
    }
}
