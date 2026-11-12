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
}
