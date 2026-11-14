<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use ArrayAccess;
use LogicException;

/**
 * The subject a consumer gives its own class at compilation instead of the `Probe` (a recording stand-in, as
 * `laravel-json-api` builds): lenient, it reads `null` and records every key read, so the consumer can tell what its
 * declaration touched.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class StandIn implements ArrayAccess
{
    /**
     * @var list<string>
     */
    public static array $read = [];

    public function __get(string $name): mixed
    {
        self::$read[] = $name;

        return null;
    }

    public function offsetExists(mixed $offset): bool
    {
        self::$read[] = $offset;

        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        self::$read[] = $offset;

        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('A stand-in is read only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('A stand-in is read only.');
    }
}
