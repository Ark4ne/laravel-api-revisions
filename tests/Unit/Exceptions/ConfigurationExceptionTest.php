<?php

declare(strict_types=1);

namespace Test\Unit\Exceptions;

use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Test\TestCase;

final class ConfigurationExceptionTest extends TestCase
{
    public function test_a_missing_or_wrong_enum_names_what_was_given(): void
    {
        $exception = ConfigurationException::notARevisionEnum('stdClass');

        self::assertSame('revisions.enum must be a backed enum implementing Ark4ne\ApiRevisions\Revisions\Revision, got stdClass.', $exception->getMessage());
    }

    public function test_an_invalid_value_names_its_key_what_it_holds_and_what_was_given(): void
    {
        self::assertSame(
            'revisions.date_format must be a non-empty string, got int.',
            ConfigurationException::invalidValue('date_format', 'a non-empty string', 'int')->getMessage(),
        );
    }
}
