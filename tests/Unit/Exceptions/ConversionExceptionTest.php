<?php

declare(strict_types=1);

namespace Test\Unit\Exceptions;

use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Enums\Priority;
use Test\TestCase;

final class ConversionExceptionTest extends TestCase
{
    public function test_invalid_enum_value_names_the_value_and_the_enum(): void
    {
        $exception = ConversionException::invalidEnumValue(OrderStatus::class, 'lost');

        self::assertSame(sprintf("'lost' is not a value of the enum %s.", OrderStatus::class), $exception->getMessage());
    }

    public function test_invalid_enum_value_writes_a_non_string_value_as_php(): void
    {
        self::assertSame(
            sprintf('2 is not a value of the enum %s.', Priority::class),
            ConversionException::invalidEnumValue(Priority::class, 2)->getMessage(),
        );
        self::assertSame(
            sprintf('3.0 is not a value of the enum %s.', Priority::class),
            ConversionException::invalidEnumValue(Priority::class, 3.0)->getMessage(),
        );
    }

    public function test_out_of_int_range_names_the_float(): void
    {
        self::assertSame('1.0E+20 is outside the int range.', ConversionException::outOfIntRange(1.0e20)->getMessage());
        self::assertSame('INF is outside the int range.', ConversionException::outOfIntRange(INF)->getMessage());
    }

    public function test_invalid_gives_its_message_as_the_reason_for_the_client(): void
    {
        $exception = ConversionException::invalid('The total must be an amount.');

        self::assertSame('The total must be an amount.', $exception->getMessage());
        self::assertSame('The total must be an amount.', $exception->reason);
        self::assertFalse($exception->declarationError);
    }

    public function test_a_value_rejected_by_a_converter_has_no_reason_and_is_no_declaration_error(): void
    {
        foreach ([ConversionException::invalidEnumValue(OrderStatus::class, 'lost'), ConversionException::outOfIntRange(INF)] as $exception) {
            self::assertNull($exception->reason);
            self::assertFalse($exception->declarationError);
        }
    }

    public function test_a_missing_conversion_is_a_declaration_error(): void
    {
        $exception = ConversionException::missing('object>string:down');

        self::assertTrue($exception->declarationError);
        self::assertNull($exception->reason);
    }

    public function test_missing_names_the_step(): void
    {
        self::assertSame(
            'No conversion object>string:down: declare down: / up: on the was(), or register a converter.',
            ConversionException::missing('object>string:down')->getMessage(),
        );
    }
}
