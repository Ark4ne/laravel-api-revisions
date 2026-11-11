<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\BoolToInt;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class BoolToIntTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'true' => [true, 1];
        yield 'false' => [false, 0];
    }

    #[DataProvider('conversions')]
    public function test_it_converts_with_the_semantics_of_the_model(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, (new BoolToInt())->convert($value));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertTrue((new BoolToInt())->lossless());
        self::assertSame([], (new BoolToInt())->extraRules());
    }
}
