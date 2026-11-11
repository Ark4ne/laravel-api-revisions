<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\IntToBool;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class IntToBoolTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'zero' => [0, false];
        yield 'one' => [1, true];
        yield 'negative' => [-5, true];
    }

    #[DataProvider('conversions')]
    public function test_it_converts_with_the_semantics_of_the_model(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, (new IntToBool())->convert($value));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertFalse((new IntToBool())->lossless());
        self::assertSame([], (new IntToBool())->extraRules());
    }
}
