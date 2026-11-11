<?php

declare(strict_types=1);

namespace Test\Unit\Support;

use Ark4ne\ApiRevisions\Support\Undefined;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\TestCase;

final class UndefinedTest extends TestCase
{
    public function test_any_instance_is_undefined(): void
    {
        self::assertTrue(Undefined::is(new Undefined()));
        self::assertTrue(Undefined::is(unserialize(serialize(new Undefined()))));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function definedValues(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty string' => [''];
        yield 'empty array' => [[]];
        yield 'object' => [new stdClass()];
    }

    #[DataProvider('definedValues')]
    public function test_null_and_empty_values_are_defined(mixed $value): void
    {
        self::assertFalse(Undefined::is($value));
    }
}
