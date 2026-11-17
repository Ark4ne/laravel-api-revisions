<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\Cast;
use Ark4ne\ApiRevisions\Conversion\ClosureConverter;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\Change;
use Ark4ne\ApiRevisions\Schema\DateSchema;
use Ark4ne\ApiRevisions\Schema\DateTimeSchema;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\FloatSchema;
use Ark4ne\ApiRevisions\Schema\IntSchema;
use Ark4ne\ApiRevisions\Schema\ListSchema;
use Ark4ne\ApiRevisions\Schema\ObjectSchema;
use Ark4ne\ApiRevisions\Schema\Presence;
use Ark4ne\ApiRevisions\Schema\StringSchema;
use Ark4ne\ApiRevisions\Support\Undefined;
use ArrayObject;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use stdClass;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\IntRevision;
use Test\Fixtures\Rules\PostcodeRule;
use Test\TestCase;

/**
 * The behaviour common to every descriptor, observed on `StringSchema`.
 */
final class DescriptorTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(StringSchema): StringSchema}>
     */
    public static function modifiers(): iterable
    {
        yield 'nullable' => [static fn(StringSchema $schema): StringSchema => $schema->nullable()];
        yield 'required' => [static fn(StringSchema $schema): StringSchema => $schema->required()];
        yield 'sometimes' => [static fn(StringSchema $schema): StringSchema => $schema->sometimes()];
        yield 'describe' => [static fn(StringSchema $schema): StringSchema => $schema->describe('Reference')];
        yield 'example' => [static fn(StringSchema $schema): StringSchema => $schema->example('A-1')];
        yield 'deprecated' => [static fn(StringSchema $schema): StringSchema => $schema->deprecated()];
        yield 'rules' => [static fn(StringSchema $schema): StringSchema => $schema->rules('alpha_dash')];
        yield 'min' => [static fn(StringSchema $schema): StringSchema => $schema->min(1)];
        yield 'max' => [static fn(StringSchema $schema): StringSchema => $schema->max(10)];
        yield 'format' => [static fn(StringSchema $schema): StringSchema => $schema->format('uuid')];
        yield 'pattern' => [static fn(StringSchema $schema): StringSchema => $schema->pattern('/^A-/')];
    }

    /**
     * @param Closure(StringSchema): StringSchema $modify
     */
    #[DataProvider('modifiers')]
    public function test_a_modifier_returns_a_new_descriptor(Closure $modify): void
    {
        $schema = new StringSchema();
        $before = serialize($schema->toType()) . serialize([$schema->isNullable(), $schema->isRequired(), $schema->isSometimes(), $schema->declaredRules()]);

        $modified = $modify($schema);

        self::assertNotSame($schema, $modified);
        self::assertSame($before, serialize($schema->toType()) . serialize([$schema->isNullable(), $schema->isRequired(), $schema->isSometimes(), $schema->declaredRules()]));
        self::assertNotSame($before, serialize($modified->toType()) . serialize([$modified->isNullable(), $modified->isRequired(), $modified->isSometimes(), $modified->declaredRules()]));
    }

    public function test_a_descriptor_without_source_reads_its_declared_key(): void
    {
        self::assertTrue((new StringSchema())->source()->isDeclaredKey());
    }

    public function test_a_descriptor_keeps_its_key_or_closure_source(): void
    {
        $closure = static fn(): string => 'x';

        self::assertSame(['customer', 'name'], (new StringSchema('customer.name'))->source()->path);
        self::assertSame($closure, (new StringSchema($closure))->source()->closure);
    }

    public function test_an_evaluated_value_is_refused_as_source(): void
    {
        $this->expectException(DefinitionException::class);

        new StringSchema(12);
    }

    public function test_a_new_descriptor_is_optional_not_nullable_without_rules(): void
    {
        $schema = new StringSchema();

        self::assertFalse($schema->isNullable());
        self::assertFalse($schema->isRequired());
        self::assertFalse($schema->isSometimes());
        self::assertSame([], $schema->declaredRules());
    }

    public function test_flags_can_be_turned_off(): void
    {
        $schema = (new StringSchema())->nullable()->required()->sometimes()->deprecated();

        $off = $schema->nullable(false)->required(false)->sometimes(false)->deprecated(false);

        self::assertFalse($off->isNullable());
        self::assertFalse($off->isRequired());
        self::assertFalse($off->isSometimes());
        self::assertFalse($off->toType()->deprecated);
    }

    public function test_rules_are_appended_and_kept_opaque(): void
    {
        $rule = new stdClass();
        $closure = static fn(): bool => true;

        $schema = (new StringSchema())->rules('alpha_dash', $rule)->rules($closure);

        self::assertSame(['alpha_dash', $rule, $closure], $schema->declaredRules());
    }

    public function test_rules_given_by_name_are_kept_as_a_list(): void
    {
        $schema = (new StringSchema())->rules(...['first' => 'alpha', 'second' => 'max:3']);

        self::assertSame(['alpha', 'max:3'], $schema->declaredRules());
    }

    public function test_the_type_carries_the_documentation(): void
    {
        $type = (new StringSchema())->describe('The reference')->example('A-1')->example('A-2')->deprecated()->toType();

        self::assertSame(Kind::String, $type->kind);
        self::assertSame('The reference', $type->description);
        self::assertSame(['A-1', 'A-2'], $type->examples);
        self::assertTrue($type->deprecated);
    }

    public function test_a_descriptor_without_configured_default_is_returned_as_is_by_configure(): void
    {
        $schema = new StringSchema();

        self::assertSame($schema, $schema->configure(new Configuration()));
    }

    public function test_serialize_returns_a_scalar_as_is(): void
    {
        self::assertSame('A-1', (new StringSchema())->serialize('A-1'));
    }

    public function test_descriptor_is_the_extension_base(): void
    {
        self::assertTrue((new ReflectionClass(Descriptor::class))->isAbstract());
    }

    public function test_a_conditional_field_is_marked_conditional(): void
    {
        $condition = static fn(mixed $value): bool => $value !== '';
        $schema = new StringSchema();

        $conditional = $schema->when($condition);

        self::assertFalse($schema->isConditional());
        self::assertSame([], $schema->conditions());
        self::assertTrue($conditional->isConditional());
        self::assertSame([$condition], $conditional->conditions());
        self::assertSame([], $conditional->presences());
    }

    public function test_unless_adds_a_negated_condition_and_marks_the_field_conditional(): void
    {
        /** @var ArrayObject<int, string> $calls */
        $calls = new ArrayObject();
        $schema = new StringSchema();

        $conditional = $schema->unless(static function () use ($calls): bool {
            $calls->append('called');

            return false;
        });

        self::assertSame([], $schema->conditions());
        self::assertTrue($conditional->isConditional());
        self::assertCount(1, $conditional->conditions());
        self::assertCount(0, $calls);
        self::assertTrue($conditional->conditions()[0](null, 'title'));
        self::assertCount(1, $calls);
    }

    public function test_conditions_are_kept_in_declaration_order(): void
    {
        $first = static fn(): bool => true;
        $second = static fn(): bool => false;

        self::assertSame([$first, $second], (new StringSchema())->when($first)->when($second)->conditions());
    }

    public function test_a_condition_is_never_called_by_the_declaration(): void
    {
        $called = false;

        (new StringSchema())->when(static function () use (&$called): bool {
            $called = true;

            return true;
        })->toType();

        self::assertFalse($called);
    }

    public function test_a_presence_condition_makes_the_field_conditional(): void
    {
        $schema = new StringSchema();

        $present = $schema->whenHas()->whenHas('customer.email');

        self::assertFalse($schema->isConditional());
        self::assertSame([], $schema->presences());
        self::assertTrue($present->isConditional());
        self::assertSame([], $present->conditions());
        self::assertEquals([Presence::has(), Presence::has('customer.email')], $present->presences());
    }

    public function test_a_relation_presence_makes_the_field_conditional(): void
    {
        $schema = new StringSchema();

        $loaded = $schema->whenLoaded('customer')->whenHas();

        self::assertSame([], $schema->presences());
        self::assertTrue($loaded->isConditional());
        self::assertEquals([Presence::loaded('customer'), Presence::has()], $loaded->presences());
    }

    public function test_a_presence_condition_refuses_an_empty_key(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Invalid presence key '': a key or each segment of a dotted path must not be empty.");

        (new StringSchema())->whenHas('');
    }

    public function test_conditions_do_not_change_the_type(): void
    {
        $schema = new StringSchema();

        self::assertEquals($schema->toType(), $schema->when(static fn(): bool => true)->whenHas()->toType());
    }

    public function test_rules_describing_their_schema_enrich_the_export_of_any_descriptor(): void
    {
        $type = (new StringSchema())->rules('alpha_num', new PostcodeRule(['pattern' => '^\\d{5}$']))->toType();

        self::assertSame(Kind::String, $type->kind);
        self::assertSame(['pattern' => '^\\d{5}$'], $type->jsonSchema);
    }

    public function test_the_keywords_of_a_custom_type_are_merged_with_those_of_its_rules(): void
    {
        $custom = new class extends Descriptor {
            public function normalize(mixed $value): mixed
            {
                return $value;
            }

            protected function type(): TypeDefinition
            {
                return TypeDefinition::of(Kind::String)->withJsonSchema(['minLength' => 3, 'pattern' => '^[A-Z]']);
            }
        };

        self::assertSame(
            ['minLength' => 3, 'pattern' => '^\\d{5}$'],
            $custom->rules(new PostcodeRule(['pattern' => '^\\d{5}$']))->toType()->jsonSchema,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function notJsonReady(): iterable
    {
        yield 'object' => [['default' => new stdClass()]];
        yield 'nested closure' => [['enum' => ['a', static fn(): string => 'b']]];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('notJsonReady')]
    public function test_a_rule_schema_that_is_not_json_ready_is_refused(array $schema): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('The JSON Schema of the rule ' . PostcodeRule::class . ' must be keywords holding scalars, arrays and nulls only.');

        (new StringSchema())->rules(new PostcodeRule($schema))->toType();
    }

    public function test_json_ready_keywords_may_nest_arrays_and_nulls(): void
    {
        $keywords = ['default' => null, 'enum' => ['a', 1, 1.5, true, ['x' => null]]];

        self::assertSame($keywords, (new StringSchema())->rules(new PostcodeRule($keywords))->toType()->jsonSchema);
    }

    public function test_a_new_descriptor_exists_in_every_revision_without_default(): void
    {
        $schema = new StringSchema();

        self::assertEquals(Lifetime::always(), $schema->lifetime());
        self::assertTrue(Undefined::is($schema->default()));
        self::assertSame([], $schema->repeatedBounds());
    }

    public function test_until_keeps_the_field_up_to_its_revision_included(): void
    {
        $schema = new StringSchema();

        $until = $schema->until(ApiRevision::Winter25);

        self::assertEquals(Lifetime::always(), $schema->lifetime());
        self::assertSame(ApiRevision::Winter25, $until->lifetime()->until);
        self::assertSame([true, true, false, false], array_map($until->lifetime()->covers(...), ApiRevision::order()));
    }

    public function test_since_keeps_the_field_from_its_revision_included(): void
    {
        $schema = new StringSchema();

        $since = $schema->since(ApiRevision::Spring26);

        self::assertEquals(Lifetime::always(), $schema->lifetime());
        self::assertSame(ApiRevision::Spring26, $since->lifetime()->since);
        self::assertSame([false, false, true, true], array_map($since->lifetime()->covers(...), ApiRevision::order()));
        self::assertTrue(Undefined::is($since->default()));
    }

    public function test_since_after_until_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('its since is newer than its until');

        (new StringSchema())->until(ApiRevision::Winter25)->since(ApiRevision::Atlas);
    }

    public function test_since_keeps_its_default_even_null(): void
    {
        self::assertNull((new StringSchema())->since(ApiRevision::Atlas, default: null)->default());
        self::assertSame('n/a', (new StringSchema())->since(ApiRevision::Atlas, 'n/a')->default());
    }

    public function test_until_keeps_the_default_of_since(): void
    {
        $schema = (new StringSchema())->since(ApiRevision::Winter25, 'n/a')->until(ApiRevision::Spring26);

        self::assertSame('n/a', $schema->default());
        self::assertSame(ApiRevision::Winter25, $schema->lifetime()->since);
        self::assertSame(ApiRevision::Spring26, $schema->lifetime()->until);
    }

    public function test_a_second_call_keeps_the_last_and_is_recorded(): void
    {
        $schema = (new StringSchema())
            ->since(ApiRevision::Winter25, 'n/a')
            ->until(ApiRevision::Atlas)
            ->since(ApiRevision::Spring26)
            ->until(ApiRevision::Spring26)
            ->until(ApiRevision::Atlas);

        self::assertSame(ApiRevision::Spring26, $schema->lifetime()->since);
        self::assertSame(ApiRevision::Atlas, $schema->lifetime()->until);
        self::assertTrue(Undefined::is($schema->default()));
        self::assertSame([
            ['bound' => 'since', 'replaced' => ApiRevision::Winter25],
            ['bound' => 'until', 'replaced' => ApiRevision::Atlas],
            ['bound' => 'until', 'replaced' => ApiRevision::Spring26],
        ], $schema->repeatedBounds());
    }

    public function test_a_single_call_of_each_bound_is_not_recorded(): void
    {
        self::assertSame([], (new StringSchema())->since(ApiRevision::Genesis)->until(ApiRevision::Atlas)->repeatedBounds());
    }

    public function test_the_lifetime_does_not_change_the_type(): void
    {
        $schema = new StringSchema();

        self::assertEquals($schema->toType(), $schema->since(ApiRevision::Genesis)->until(ApiRevision::Atlas)->toType());
    }

    public function test_in_group_returns_a_new_descriptor_and_records_no_repeated_bound(): void
    {
        $schema = (new StringSchema())->since(ApiRevision::Atlas, 'own')->until(ApiRevision::Atlas);
        $lifetime = Lifetime::always()->withSince(ApiRevision::Winter25)->withUntil(ApiRevision::Spring26);

        $grouped = $schema->inGroup($lifetime, null);

        self::assertSame($lifetime, $grouped->lifetime());
        self::assertNull($grouped->default());
        self::assertSame([], $grouped->repeatedBounds());
        self::assertSame(ApiRevision::Atlas, $schema->lifetime()->since);
        self::assertSame('own', $schema->default());
    }

    public function test_was_returns_a_new_descriptor(): void
    {
        $down = static fn(string $status): string => $status;
        $schema = new StringSchema();

        $changed = $schema->was(ApiRevision::Winter25, name: 'state', schema: new IntSchema(), down: $down);

        self::assertSame([], $schema->history()->changes);
        $change = $changed->history()->changes[0];
        self::assertSame(ApiRevision::Winter25, $change->revision);
        self::assertSame('state', $change->name);
        self::assertInstanceOf(IntSchema::class, $change->schema);
        self::assertInstanceOf(ClosureConverter::class, $change->down);
        self::assertSame($down, $change->down->closure);
        self::assertNull($change->up);
    }

    public function test_was_entries_are_ordered_by_revision_not_by_call(): void
    {
        $schema = (new StringSchema())
            ->was(ApiRevision::Genesis, schema: new FloatSchema())
            ->was(ApiRevision::Spring26, name: 'amount')
            ->was(ApiRevision::Winter25, schema: new StringSchema());

        self::assertSame(
            [ApiRevision::Spring26, ApiRevision::Winter25, ApiRevision::Genesis],
            array_map(static fn(Change $change): Revision => $change->revision, $schema->history()->changes),
        );
    }

    public function test_was_on_the_head_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('the head has no older shape');

        (new StringSchema())->was(ApiRevision::Atlas, name: 'state');
    }

    public function test_two_was_on_the_same_revision_throw(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('this revision already has a was()');

        (new StringSchema())->was(ApiRevision::Winter25, name: 'state')->was(ApiRevision::Winter25, schema: new IntSchema());
    }

    public function test_was_with_an_empty_name_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(sprintf('Invalid was(%s::Winter25): a name must not be empty.', ApiRevision::class));

        (new StringSchema())->was(ApiRevision::Winter25, name: ''); // @phpstan-ignore argument.type
    }

    public function test_was_has_no_effect_on_the_head_type(): void
    {
        $schema = (new StringSchema('status'))->max(10)->nullable()->since(ApiRevision::Genesis);

        $changed = $schema->was(ApiRevision::Winter25, name: 'state', schema: (new IntSchema())->min(1));

        self::assertEquals($schema->toType(), $changed->toType());
        self::assertSame($schema->source(), $changed->source());
        self::assertTrue($changed->isNullable());
        self::assertEquals($schema->lifetime(), $changed->lifetime());
    }

    public function test_the_lifetime_and_the_changes_live_in_the_history(): void
    {
        $schema = (new StringSchema())->was(ApiRevision::Winter25, name: 'state')->since(ApiRevision::Genesis)->until(ApiRevision::Spring26);

        self::assertSame(ApiRevision::Genesis, $schema->history()->lifetime->since);
        self::assertSame(ApiRevision::Spring26, $schema->lifetime()->until);
        self::assertCount(1, $schema->history()->changes);
    }

    /**
     * @return iterable<string, array{Closure(): Descriptor<mixed>}>
     */
    public static function schemasWithAHistory(): iterable
    {
        yield 'a key source' => [static fn(): Descriptor => new IntSchema('total_cents')];
        yield 'a closure source' => [static fn(): Descriptor => new IntSchema(static fn(): int => 1)];
        yield 'a lifetime' => [static fn(): Descriptor => (new IntSchema())->since(ApiRevision::Genesis)];
        yield 'an until' => [static fn(): Descriptor => (new IntSchema())->until(ApiRevision::Genesis)];
        yield 'a history' => [static fn(): Descriptor => (new IntSchema())->was(ApiRevision::Genesis, name: 'old')];
        yield 'a default' => [static fn(): Descriptor => (new IntSchema())->inGroup(Lifetime::always(), 0)];
        yield 'a condition' => [static fn(): Descriptor => (new IntSchema())->when(static fn(): bool => true)];
        yield 'a presence' => [static fn(): Descriptor => (new IntSchema())->whenHas()];
        yield 'a child with a source' => [static fn(): Descriptor => new ObjectSchema(['amount' => new IntSchema('cents')])];
        yield 'a child with a history' => [static fn(): Descriptor => new ObjectSchema(['amount' => (new IntSchema())->was(ApiRevision::Genesis, name: 'old')])];
        yield 'items with a source' => [static fn(): Descriptor => new ListSchema(new IntSchema('cents'))];
        yield 'nested items with a default' => [static fn(): Descriptor => new ListSchema(new ObjectSchema(['a' => (new IntSchema())->inGroup(Lifetime::always(), 0)]))];
    }

    /**
     * @param Closure(): Descriptor<mixed> $schema
     */
    #[DataProvider('schemasWithAHistory')]
    public function test_a_was_schema_with_its_own_source_or_history_throws(Closure $schema): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(sprintf(
            'The schema of was(%s::Winter25) and its items or children must declare no source, lifetime, history, default nor condition: older values derive from the head value through down:.',
            ApiRevision::class,
        ));

        (new StringSchema())->was(ApiRevision::Winter25, schema: $schema());
    }

    public function test_a_was_composite_whose_parts_describe_only_types_is_accepted(): void
    {
        $object = new ObjectSchema(['amount' => new IntSchema(), 'tags' => new ListSchema(new StringSchema())]);

        $schema = (new StringSchema())->was(ApiRevision::Winter25, schema: new ListSchema($object));

        self::assertInstanceOf(ListSchema::class, $schema->history()->changes[0]->schema);
    }

    public function test_a_was_that_changes_nothing_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(sprintf('Invalid was(%s::Winter25): it changes nothing (give a name, a schema, down: or up:).', ApiRevision::class));

        (new StringSchema())->was(ApiRevision::Winter25);
    }

    public function test_a_was_with_only_a_conversion_is_a_change(): void
    {
        $down = static fn(string $value): string => strtoupper($value);

        $change = (new StringSchema())->was(ApiRevision::Winter25, down: $down)->history()->changes[0];

        self::assertInstanceOf(ClosureConverter::class, $change->down);
        self::assertSame($down, $change->down->closure);
        self::assertCount(1, (new StringSchema())->was(ApiRevision::Winter25, up: $down)->history()->changes);
    }

    public function test_a_was_closure_receives_the_subject(): void
    {
        $down = static fn(int $cents, string $currency): string => $currency . ' ' . $cents;

        $change = (new IntSchema())->was(ApiRevision::Winter25, schema: new StringSchema(), down: $down)->history()->changes[0];

        self::assertSame('EUR 1250', $change->down?->convert(1250, 'EUR'));
    }

    public function test_a_was_keeps_a_cast_as_it_is(): void
    {
        $round = Cast::round();

        $change = (new IntSchema())->was(ApiRevision::Winter25, schema: new FloatSchema(), up: $round)->history()->changes[0];

        self::assertSame($round, $change->up);
        self::assertNull($change->down);
    }

    public function test_a_was_of_another_enum_than_the_lifetime_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Revisions of two enums cannot be mixed');

        (new StringSchema())->since(ApiRevision::Genesis)->was(IntRevision::First, name: 'old');
    }

    public function test_a_lifetime_of_another_enum_than_the_changes_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Revisions of two enums cannot be mixed');

        (new StringSchema())->was(ApiRevision::Winter25, name: 'old')->until(IntRevision::Third);
    }

    public function test_configured_resolves_the_schemas_of_the_history(): void
    {
        $descriptor = (new DateTimeSchema())->was(ApiRevision::Winter25, schema: new DateSchema());

        $configured = $descriptor->configured(new Configuration(dateFormat: 'd/m/Y', dateTimeFormat: 'Y-m-d H:i'));

        self::assertSame('Y-m-d H:i', $configured->toType()->format);
        self::assertSame('d/m/Y', $configured->history()->changes[0]->schema?->toType()->format);
        self::assertSame('Y-m-d', $descriptor->history()->changes[0]->schema?->toType()->format);
    }

    public function test_configured_returns_a_new_descriptor_even_with_nothing_to_resolve(): void
    {
        $schema = new StringSchema();

        $configured = $schema->configured(new Configuration());

        self::assertNotSame($schema, $configured);
        self::assertEquals($schema, $configured);
    }
}
