<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\FloatToInt;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class FloatToIntTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'truncated' => [12.7, 12];
        yield 'negative truncated toward zero' => [-12.7, -12];
        yield 'integral' => [3.0, 3];
    }

    #[DataProvider('conversions')]
    public function test_it_converts_with_the_semantics_of_the_model(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, (new FloatToInt())->convert($value));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertFalse((new FloatToInt())->lossless());
        self::assertSame([], (new FloatToInt())->extraRules());
    }

    public function test_a_float_outside_the_int_range_throws(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('1.0E+20 is outside the int range.');

        (new FloatToInt())->convert(1.0e20);
    }
}
