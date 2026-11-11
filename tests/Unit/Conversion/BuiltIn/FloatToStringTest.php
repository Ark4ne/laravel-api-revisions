<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\FloatToString;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class FloatToStringTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'integral' => [12.0, '12'];
        yield 'decimal' => [0.1, '0.1'];
        yield 'sum' => [0.1 + 0.2, '0.30000000000000004'];
        yield 'negative' => [-2.5, '-2.5'];
        yield 'large' => [1e20, '1.0E+20'];
    }

    #[DataProvider('conversions')]
    public function test_it_converts_with_the_semantics_of_the_model(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, (new FloatToString())->convert($value));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertTrue((new FloatToString())->lossless());
        self::assertSame([], (new FloatToString())->extraRules());
    }
}
