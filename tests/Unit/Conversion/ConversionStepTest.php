<?php

declare(strict_types=1);

namespace Test\Unit\Conversion;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\IntToString;
use Ark4ne\ApiRevisions\Conversion\ClosureConverter;
use Ark4ne\ApiRevisions\Conversion\ConversionStep;
use Ark4ne\ApiRevisions\Conversion\StepKind;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Test\TestCase;

final class ConversionStepTest extends TestCase
{
    public function test_with_converter_returns_a_new_step_keeping_its_references(): void
    {
        $step = new ConversionStep(StepKind::Explicit, 'int>string:down', TypeDefinition::of(Kind::Int), TypeDefinition::of(Kind::String), 2);
        $converter = new IntToString();

        $bound = $step->withConverter($converter);

        self::assertNull($step->converter);
        self::assertSame($converter, $bound->converter);
        self::assertSame(StepKind::Explicit, $bound->kind);
        self::assertSame('int>string:down', $bound->key);
        self::assertSame($step->from, $bound->from);
        self::assertSame($step->to, $bound->to);
        self::assertSame(2, $bound->change);
    }

    public function test_apply_converts_with_the_bound_converter(): void
    {
        $step = new ConversionStep(StepKind::Registered, 'int>string:down', TypeDefinition::of(Kind::Int), TypeDefinition::of(Kind::String), 0);

        self::assertSame('3', $step->withConverter(new IntToString())->apply(3));
    }

    public function test_apply_without_converter_throws(): void
    {
        $step = new ConversionStep(StepKind::Missing, 'object>string:down', TypeDefinition::of(Kind::Object), TypeDefinition::of(Kind::String), 0);

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('No conversion object>string:down');

        $step->apply([]);
    }

    public function test_a_step_serializes_without_its_converter(): void
    {
        $step = new ConversionStep(StepKind::Explicit, 'int>string:down', TypeDefinition::of(Kind::Int), TypeDefinition::of(Kind::String), 1);

        $restored = unserialize(serialize($step->withConverter(new ClosureConverter(static fn(int $value): string => (string) $value))));

        self::assertInstanceOf(ConversionStep::class, $restored);
        self::assertEquals($step, $restored);
        self::assertNull($restored->converter);
    }
}
