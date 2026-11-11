<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\StringSchema;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use stdClass;
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

    public function test_serialize_returns_a_scalar_as_is(): void
    {
        self::assertSame('A-1', (new StringSchema())->serialize('A-1'));
    }

    public function test_descriptor_is_the_extension_base(): void
    {
        self::assertTrue((new ReflectionClass(Descriptor::class))->isAbstract());
    }
}
