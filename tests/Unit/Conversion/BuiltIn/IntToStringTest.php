<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\IntToString;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class IntToStringTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'positive' => [12, '12'];
        yield 'negative' => [-3, '-3'];
        yield 'max' => [PHP_INT_MAX, '9223372036854775807'];
    }

    #[DataProvider('conversions')]
    public function test_it_converts_with_the_semantics_of_the_model(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, (new IntToString())->convert($value));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertTrue((new IntToString())->lossless());
        self::assertSame([], (new IntToString())->extraRules());
    }
}
