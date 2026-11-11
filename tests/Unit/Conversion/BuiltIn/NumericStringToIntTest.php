<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\NumericStringToInt;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class NumericStringToIntTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'digits' => ['12', 12];
        yield 'leading zeros' => ['-007', -7];
        yield 'decimal truncated' => ['12.7', 12];
        yield 'exponent' => ['1e3', 1000];
        yield 'not numeric' => ['abc', 0];
    }

    #[DataProvider('conversions')]
    public function test_it_converts_with_the_semantics_of_the_model(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, (new NumericStringToInt())->convert($value));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertFalse((new NumericStringToInt())->lossless());
        self::assertSame(['integer'], (new NumericStringToInt())->extraRules());
    }
}
