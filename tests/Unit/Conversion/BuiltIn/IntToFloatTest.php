<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\IntToFloat;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class IntToFloatTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'positive' => [12, 12.0];
        yield 'negative' => [-3, -3.0];
        yield 'zero' => [0, 0.0];
    }

    #[DataProvider('conversions')]
    public function test_it_converts_with_the_semantics_of_the_model(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, (new IntToFloat())->convert($value));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertTrue((new IntToFloat())->lossless());
        self::assertSame([], (new IntToFloat())->extraRules());
    }
}
