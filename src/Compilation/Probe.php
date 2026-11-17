<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Compilation;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use ArrayAccess;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The subject of a class during compilation (Null Object, "describe mode"): it holds nothing and throws
 * `DefinitionException::subjectReadAtCompilation()` on any access, so a declaration reading its subject eagerly, or a
 * source run during compilation, is caught at once.
 *
 * @implements ArrayAccess<mixed, mixed>
 * @implements IteratorAggregate<mixed, mixed>
 */
final class Probe implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * @param class-string $class the class being compiled, named by the exception
     */
    public function __construct(private readonly string $class) {}

    public function __get(string $name): never
    {
        throw $this->read("->{$name}");
    }

    public function __set(string $name, mixed $value): never
    {
        throw $this->read("->{$name} =");
    }

    public function __isset(string $name): never
    {
        throw $this->read("isset(->{$name})");
    }

    public function __unset(string $name): never
    {
        throw $this->read("unset(->{$name})");
    }

    /**
     * @param array<mixed> $arguments
     */
    public function __call(string $name, array $arguments): never
    {
        throw $this->read("->{$name}()");
    }

    public function offsetExists(mixed $offset): never
    {
        throw $this->read('isset([' . var_export($offset, true) . '])');
    }

    public function offsetGet(mixed $offset): never
    {
        throw $this->read('[' . var_export($offset, true) . ']');
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw $this->read('[' . var_export($offset, true) . '] =');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw $this->read('unset([' . var_export($offset, true) . '])');
    }

    public function count(): never
    {
        throw $this->read('count()');
    }

    public function getIterator(): Traversable
    {
        throw $this->read('iteration');
    }

    public function __toString(): string
    {
        throw $this->read('string cast');
    }

    private function read(string $access): DefinitionException
    {
        return DefinitionException::subjectReadAtCompilation($this->class, $access);
    }
}
