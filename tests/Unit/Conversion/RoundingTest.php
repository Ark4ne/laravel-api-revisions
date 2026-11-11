<?php

declare(strict_types=1);

namespace Test\Unit\Conversion;

use Ark4ne\ApiRevisions\Conversion\ExplicitConverter;
use Ark4ne\ApiRevisions\Conversion\Rounding;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class RoundingTest extends TestCase
{
    /**
     * @return iterable<string, array{'round'|'floor'|'ceil'}>
     */
    public static function modes(): iterable
    {
        yield 'round' => ['round'];
        yield 'floor' => ['floor'];
        yield 'ceil' => ['ceil'];
    }

    /**
     * @param 'round'|'floor'|'ceil' $mode
     */
    #[DataProvider('modes')]
    public function test_a_rounding_is_explicit_and_lossy_without_rules(string $mode): void
    {
        $rounding = new Rounding($mode);

        self::assertContains(ExplicitConverter::class, class_implements($rounding));
        self::assertFalse($rounding->lossless());
        self::assertSame([], $rounding->extraRules());
    }

    /**
     * @param 'round'|'floor'|'ceil' $mode
     */
    #[DataProvider('modes')]
    public function test_a_rounding_passes_an_int_and_a_foreign_value_through(string $mode): void
    {
        self::assertSame(7, (new Rounding($mode))->convert(7));
        self::assertSame('2.5', (new Rounding($mode))->convert('2.5'));
    }

    /**
     * @param 'round'|'floor'|'ceil' $mode
     */
    #[DataProvider('modes')]
    public function test_a_rounding_reaches_the_lowest_int(string $mode): void
    {
        self::assertSame(PHP_INT_MIN, (new Rounding($mode))->convert(-(2.0 ** 63)));
    }

    /**
     * @return iterable<string, array{'round'|'floor'|'ceil', float}>
     */
    public static function outOfRange(): iterable
    {
        foreach (['round', 'floor', 'ceil'] as $mode) {
            yield "{$mode} 2^63" => [$mode, 2.0 ** 63];
            yield "{$mode} -1e20" => [$mode, -1.0e20];
            yield "{$mode} INF" => [$mode, INF];
            yield "{$mode} NAN" => [$mode, NAN];
        }
    }

    /**
     * @param 'round'|'floor'|'ceil' $mode
     */
    #[DataProvider('outOfRange')]
    public function test_a_float_outside_the_int_range_throws(string $mode, float $value): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('is outside the int range.');

        (new Rounding($mode))->convert($value);
    }
}
