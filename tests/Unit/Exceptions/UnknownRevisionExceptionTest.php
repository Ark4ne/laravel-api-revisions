<?php

declare(strict_types=1);

namespace Test\Unit\Exceptions;

use Ark4ne\ApiRevisions\Exceptions\UnknownRevisionException;
use Test\TestCase;

final class UnknownRevisionExceptionTest extends TestCase
{
    public function test_an_unknown_revision_names_the_value_and_the_accepted_ones(): void
    {
        $exception = UnknownRevisionException::unknown('2020-01', ['genesis', 'atlas']);

        self::assertSame("Unknown API revision '2020-01', expected one of: genesis, atlas.", $exception->getMessage());
        self::assertSame('2020-01', $exception->value);
        self::assertSame(['genesis', 'atlas'], $exception->accepted);
    }

    public function test_a_long_value_is_bounded_to_64_characters(): void
    {
        $exception = UnknownRevisionException::unknown(str_repeat('é', 100), ['atlas']);

        self::assertSame(str_repeat('é', 63) . '…', $exception->value);
        self::assertSame("Unknown API revision '" . str_repeat('é', 63) . "…', expected one of: atlas.", $exception->getMessage());
    }

    public function test_a_value_of_64_characters_is_kept_whatever_its_bytes(): void
    {
        self::assertSame(str_repeat('a', 64), UnknownRevisionException::unknown(str_repeat('a', 64), [])->value);
        self::assertSame(str_repeat('é', 40), UnknownRevisionException::unknown(str_repeat('é', 40), [])->value);
        self::assertSame(str_repeat('a', 63) . '…', UnknownRevisionException::unknown(str_repeat('a', 65), [])->value);
    }
}
