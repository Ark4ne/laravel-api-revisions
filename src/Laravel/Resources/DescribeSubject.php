<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Resources;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The subject of a `RevisionedResource` during compilation (Null Object): unlike the core `Probe`, it
 * never throws, every read gives `null`, so that the plain values and native conditions of `toArray()` evaluate (an
 * eager value is typed, not run: a nullable `any`). As an Eloquent model it has no attribute and no relation loaded:
 * the native `whenHas()`, `whenLoaded()` and `whenCounted()` are missing.
 *
 * @implements ArrayAccess<mixed, mixed>
 * @implements IteratorAggregate<never, never>
 *
 * @internal built by `RevisionedResource::describeRevisioned()`
 */
final class DescribeSubject implements ArrayAccess, Countable, IteratorAggregate
{
    public function __get(string $name): mixed
    {
        return null;
    }

    public function __set(string $name, mixed $value): void {}

    public function __isset(string $name): bool
    {
        return false;
    }

    public function __unset(string $name): void {}

    /**
     * @param array<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }

    /**
     * No attribute (as an Eloquent model).
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return [];
    }

    /**
     * No relation loaded (as an Eloquent model).
     */
    public function relationLoaded(string $key): bool
    {
        return false;
    }

    public function offsetExists(mixed $offset): bool
    {
        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    public function count(): int
    {
        return 0;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator([]);
    }

    public function __toString(): string
    {
        return '';
    }
}
