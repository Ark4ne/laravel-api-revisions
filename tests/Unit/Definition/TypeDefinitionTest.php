<?php

declare(strict_types=1);

namespace Test\Unit\Definition;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use stdClass;
use Test\TestCase;

final class TypeDefinitionTest extends TestCase
{
    public function test_a_type_of_a_kind_has_no_constraint_and_no_documentation(): void
    {
        $type = TypeDefinition::of(Kind::Int);

        self::assertSame(Kind::Int, $type->kind);
        self::assertNull($type->min);
        self::assertNull($type->max);
        self::assertNull($type->format);
        self::assertNull($type->pattern);
        self::assertNull($type->description);
        self::assertSame([], $type->examples);
        self::assertFalse($type->deprecated);
        self::assertNull($type->enum);
        self::assertNull($type->backing);
        self::assertSame([], $type->values);
        self::assertNull($type->items);
        self::assertSame([], $type->children);
    }

    public function test_items_and_children_are_kept_by_every_wither(): void
    {
        $string = TypeDefinition::of(Kind::String);

        $list = TypeDefinition::of(Kind::List)->withItems($string)->withMin(1)->withDescription('Tags');
        $object = TypeDefinition::of(Kind::Object)->withChildren(['street' => $string])->withDeprecated(true);

        self::assertSame($string, $list->items);
        self::assertSame(1, $list->min);
        self::assertSame(['street' => $string], $object->children);
        self::assertTrue($object->deprecated);
        self::assertEquals($object, unserialize(serialize($object)));
    }

    public function test_withers_return_a_new_type_and_keep_the_others_values(): void
    {
        $type = TypeDefinition::of(Kind::String);

        $described = $type
            ->withMin(1)
            ->withMax(2.5)
            ->withFormat('email')
            ->withPattern('/^a/')
            ->withDescription('The reference')
            ->addExample('a@b.c')
            ->addExample('d@e.f')
            ->withDeprecated(true)
            ->withJsonSchema(['minLength' => 1]);

        self::assertNull($type->min);
        self::assertSame([], $type->jsonSchema);
        self::assertSame(['minLength' => 1], $described->jsonSchema);
        self::assertSame(Kind::String, $described->kind);
        self::assertSame(1, $described->min);
        self::assertSame(2.5, $described->max);
        self::assertSame('email', $described->format);
        self::assertSame('/^a/', $described->pattern);
        self::assertSame('The reference', $described->description);
        self::assertSame(['a@b.c', 'd@e.f'], $described->examples);
        self::assertTrue($described->deprecated);
    }

    public function test_with_enum_sets_the_enum_and_every_wither_keeps_it(): void
    {
        $type = TypeDefinition::of(Kind::Enum)->withEnum(Kind::class, Kind::String, ['string', 'int']);

        $described = $type->withMin(1)->withMax(2)->withFormat('f')->withPattern('/p/')->withDescription('d')->addExample('string')->withDeprecated(true);

        self::assertNull(TypeDefinition::of(Kind::Enum)->enum);
        self::assertSame(Kind::class, $described->enum);
        self::assertSame(Kind::String, $described->backing);
        self::assertSame(['string', 'int'], $described->values);
        self::assertSame('d', $described->description);
    }

    public function test_a_type_is_serializable(): void
    {
        $type = TypeDefinition::of(Kind::Float)->withMin(0)->addExample(12.5);
        $enum = TypeDefinition::of(Kind::Enum)->withEnum(Kind::class, Kind::String, ['string', 'int']);

        self::assertEquals($type, unserialize(serialize($type)));
        self::assertEquals($enum, unserialize(serialize($enum)));
    }

    public function test_a_custom_type_has_its_kind_and_name_and_every_wither_keeps_it(): void
    {
        $type = TypeDefinition::custom('money', ['type' => 'object'])->withDescription('Total')->withJsonSchema(['minProperties' => 2]);

        self::assertSame(Kind::Custom, $type->kind);
        self::assertSame('money', $type->name);
        self::assertSame(['type' => 'object'], $type->fragment);
        self::assertSame(['minProperties' => 2], $type->jsonSchema);
        self::assertNull(TypeDefinition::of(Kind::String)->name);
        self::assertSame([], TypeDefinition::of(Kind::String)->fragment);
    }

    public function test_a_custom_type_needs_a_name(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Invalid type name ''");

        TypeDefinition::custom('');
    }

    public function test_a_custom_type_fragment_must_be_json_ready(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("The JSON Schema fragment of the custom type 'money' must hold scalars, arrays and nulls only.");

        TypeDefinition::custom('money', ['default' => new stdClass()]);
    }
}
