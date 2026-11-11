<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Presence;
use Ark4ne\ApiRevisions\Schema\StringSchema;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use stdClass;
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
}
