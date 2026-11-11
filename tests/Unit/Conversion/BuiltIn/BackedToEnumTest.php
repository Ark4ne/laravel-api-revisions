<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\BackedToEnum;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Enums\Priority;
use Test\Fixtures\Enums\StatusCode;
use Test\TestCase;

final class BackedToEnumTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'value' => ['paid', OrderStatus::Paid];
    }

    #[DataProvider('conversions')]
    public function test_it_converts_with_the_semantics_of_the_model(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, (new BackedToEnum(OrderStatus::class, Kind::String))->convert($value));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertTrue((new BackedToEnum(OrderStatus::class, Kind::String))->lossless());
        self::assertSame([], (new BackedToEnum(OrderStatus::class, Kind::String))->extraRules());
    }

    /**
     * @return iterable<string, array{mixed, Priority}>
     */
    public static function intBacked(): iterable
    {
        yield 'int' => [3, Priority::High];
        yield 'digits' => ['3', Priority::High];
        yield 'one' => ['1', Priority::Low];
    }

    #[DataProvider('intBacked')]
    public function test_an_int_backed_enum_reads_ints_and_digit_strings(mixed $value, Priority $expected): void
    {
        self::assertSame($expected, (new BackedToEnum(Priority::class, Kind::Int))->convert($value));
    }

    /**
     * @return iterable<string, array{BackedToEnum, mixed, string}>
     */
    public static function unknownValues(): iterable
    {
        yield 'unknown string' => [new BackedToEnum(OrderStatus::class, Kind::String), 'lost', "'lost'"];
        yield 'int not a case of a string enum' => [new BackedToEnum(OrderStatus::class, Kind::String), 12, '12'];
        yield 'unknown int' => [new BackedToEnum(Priority::class, Kind::Int), 2, '2'];
        yield 'not digits' => [new BackedToEnum(Priority::class, Kind::Int), '3.0', "'3.0'"];
        yield 'blank' => [new BackedToEnum(Priority::class, Kind::Int), ' 3', "' 3'"];
        yield 'float to a digit string enum' => [new BackedToEnum(StatusCode::class, Kind::String), 404.0, '404.0'];
        yield 'zero padded' => [new BackedToEnum(Priority::class, Kind::Int), '01', "'01'"];
        yield 'negative zero' => [new BackedToEnum(Priority::class, Kind::Int), '-0', "'-0'"];
        yield 'beyond the int range' => [new BackedToEnum(Priority::class, Kind::Int), '99999999999999999999', "'99999999999999999999'"];
        yield 'trailing newline' => [new BackedToEnum(Priority::class, Kind::Int), "3\n", "'3\n'"];
        yield 'float' => [new BackedToEnum(Priority::class, Kind::Int), 3.0, '3.0'];
    }

    public function test_a_string_backed_enum_reads_an_int_as_its_digits(): void
    {
        self::assertSame(StatusCode::NotFound, (new BackedToEnum(StatusCode::class, Kind::String))->convert(404));
    }

    #[DataProvider('unknownValues')]
    public function test_an_unknown_enum_value_throws_a_conversion_exception(BackedToEnum $converter, mixed $value, string $written): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage("{$written} is not a value of the enum ");

        $converter->convert($value);
    }
}
