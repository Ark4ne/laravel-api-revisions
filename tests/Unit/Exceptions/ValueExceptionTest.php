<?php

declare(strict_types=1);

namespace Test\Unit\Exceptions;

use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class ValueExceptionTest extends TestCase
{
    public function test_a_refused_value_names_the_class_the_field_the_revision_and_the_types(): void
    {
        $exception = ValueException::refused('App\OrderResource', 'total', ApiRevision::Winter25, 'int', 'string');

        self::assertSame(
            'App\OrderResource field total at Test\Fixtures\ApiRevision::Winter25: int expected, string given.',
            $exception->getMessage(),
        );
    }

    public function test_a_failed_conversion_names_the_types_never_the_value(): void
    {
        $exception = ValueException::unconvertible('App\\OrderResource', 'total', ApiRevision::Genesis, 'float', 'int');

        self::assertNull($exception->getPrevious());
        self::assertSame('App\\OrderResource field total at Test\\Fixtures\\ApiRevision::Genesis: float cannot be converted to int.', $exception->getMessage());
    }
}
