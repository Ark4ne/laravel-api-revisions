<?php

declare(strict_types=1);

namespace Test\Unit\Definition;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
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
            ->withDeprecated(true);

        self::assertNull($type->min);
        self::assertSame(Kind::String, $described->kind);
        self::assertSame(1, $described->min);
        self::assertSame(2.5, $described->max);
        self::assertSame('email', $described->format);
        self::assertSame('/^a/', $described->pattern);
        self::assertSame('The reference', $described->description);
        self::assertSame(['a@b.c', 'd@e.f'], $described->examples);
        self::assertTrue($described->deprecated);
    }

    public function test_a_type_is_serializable(): void
    {
        $type = TypeDefinition::of(Kind::Float)->withMin(0)->addExample(12.5);

        self::assertEquals($type, unserialize(serialize($type)));
    }
}
