<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\NumericStringToFloat;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class NumericStringToFloatTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'decimal' => ['12.5', 12.5];
        yield 'integer' => ['3', 3.0];
        yield 'exponent' => ['1e3', 1000.0];
        yield 'not numeric' => ['abc', 0.0];
    }

    #[DataProvider('conversions')]
    public function test_it_converts_with_the_semantics_of_the_model(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, (new NumericStringToFloat())->convert($value));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertFalse((new NumericStringToFloat())->lossless());
        self::assertSame(['numeric'], (new NumericStringToFloat())->extraRules());
    }
}
