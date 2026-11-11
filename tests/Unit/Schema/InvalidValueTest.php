<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\InvalidValue;
use Test\TestCase;

final class InvalidValueTest extends TestCase
{
    public function test_an_invalid_value_keeps_the_value_the_expected_kind_and_the_cast(): void
    {
        $invalid = new InvalidValue('abc', Kind::Int, 0);

        self::assertSame('abc', $invalid->value);
        self::assertSame(Kind::Int, $invalid->expected);
        self::assertSame(0, $invalid->cast);
    }
}
