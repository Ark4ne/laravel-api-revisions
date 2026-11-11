<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\EnumToBacked;
use Ark4ne\ApiRevisions\Definition\Kind;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Enums\Priority;
use Test\TestCase;

final class EnumToBackedTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'string backed to string' => [OrderStatus::Paid, 'paid'];
        yield 'int backed to string' => [Priority::High, '3'];
    }

    #[DataProvider('conversions')]
    public function test_it_converts_with_the_semantics_of_the_model(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, (new EnumToBacked(Kind::String))->convert($value));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertTrue((new EnumToBacked(Kind::String))->lossless());
        self::assertSame([], (new EnumToBacked(Kind::String))->extraRules());
    }

    public function test_an_int_backed_enum_converts_to_its_int(): void
    {
        self::assertSame(3, (new EnumToBacked(Kind::Int))->convert(Priority::High));
    }
}
