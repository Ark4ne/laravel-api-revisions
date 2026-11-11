<?php

declare(strict_types=1);

namespace Test\Unit\Support;

use Ark4ne\ApiRevisions\Support\Floats;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class FloatsTest extends TestCase
{
    /**
     * @return iterable<string, array{float, string}>
     */
    public static function floats(): iterable
    {
        yield 'integral' => [12.0, '12'];
        yield 'short decimal' => [0.1, '0.1'];
        yield 'every digit kept' => [0.1 + 0.2, '0.30000000000000004'];
        yield 'negative' => [-2.5, '-2.5'];
        yield 'large' => [1e20, '1.0E+20'];
        yield 'small' => [1.5e-10, '1.5E-10'];
    }

    #[DataProvider('floats')]
    public function test_a_float_is_written_with_the_fewest_digits_that_read_back_the_same(float $value, string $expected): void
    {
        $previous = ini_set('precision', '5');
        try {
            self::assertSame($expected, Floats::toString($value));
            self::assertSame($value, (float) Floats::toString($value));
        } finally {
            ini_set('precision', (string) $previous);
        }
    }

    /**
     * @return iterable<string, array{float, ?int}>
     */
    public static function ints(): iterable
    {
        yield 'truncated toward zero' => [-12.7, -12];
        yield 'lowest int' => [-(2.0 ** 63), PHP_INT_MIN];
        yield 'highest float below 2^63' => [9.2233720368547748E18, 9223372036854774784];
        yield '2^63' => [2.0 ** 63, null];
        yield 'below -2^63' => [-1.0e19, null];
        yield 'infinity' => [INF, null];
        yield 'negative infinity' => [-INF, null];
        yield 'not a number' => [NAN, null];
    }

    #[DataProvider('ints')]
    public function test_to_int_gives_the_int_or_null_outside_the_int_range(float $value, ?int $expected): void
    {
        self::assertSame($expected, Floats::toInt($value));
    }
}
