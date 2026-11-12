<?php

declare(strict_types=1);

namespace Test\Unit\Exceptions;

use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Exceptions\InvalidInputException;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class InvalidInputExceptionTest extends TestCase
{
    public function test_a_conversion_rejected_keeps_the_key_the_reason_and_the_cause(): void
    {
        $cause = ConversionException::invalid('Not an amount.');

        $exception = InvalidInputException::at('lines.0.total', $cause);

        self::assertSame('lines.0.total', $exception->key);
        self::assertSame('Not an amount.', $exception->reason);
        self::assertSame($cause, $exception->getPrevious());
        self::assertSame('The input lines.0.total is invalid: Not an amount.', $exception->getMessage());
    }

    public function test_a_value_refused_has_no_reason(): void
    {
        $cause = ValueException::refused('object', 'count', ApiRevision::Winter25, 'int', 'string');

        $exception = InvalidInputException::at('number', $cause);

        self::assertNull($exception->reason);
        self::assertSame("The input number is invalid: {$cause->getMessage()}", $exception->getMessage());
    }
}
