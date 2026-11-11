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
}
