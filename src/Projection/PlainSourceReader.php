<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Projection;

use ArrayAccess;

/**
 * The core `SourceReader` (model.md §6.2): arrays and `ArrayAccess` by offset, objects by public property, then
 * `__get()`. A key the subject does not have reads `null`; `null` in a path stops it. The errors of the subject itself
 * (a strict mode refusing a missing attribute) are never hidden: no `isset()` before `__get()`. A relation is a key:
 * `loaded()` is `has()`.
 */
final readonly class PlainSourceReader implements SourceReader
{
    /**
     * The value at `$path` on `$subject`, `null` when a segment is missing or `null`.
     *
     * @param non-empty-list<string> $path
     */
    public function read(mixed $subject, array $path): mixed
    {
        foreach ($path as $key) {
            $subject = $this->key($subject, $key);
        }

        return $subject;
    }

    /**
     * Whether the subject has every segment of `$path` (a `whenHas()`), a key holding `null` included.
     *
     * @param non-empty-list<string> $path
     */
    public function has(mixed $subject, array $path): bool
    {
        foreach ($path as $key) {
            if (!$this->holds($subject, $key)) {
                return false;
            }
            $subject = $this->key($subject, $key);
        }

        return true;
    }

    /**
     * The value of `$key` when the subject already holds it without side effect (an array offset that exists, an
     * initialized public property), `null` otherwise: never a magic getter, never an `ArrayAccess` call. The `$value`
     * given to a source closure.
     */
    public function peek(mixed $subject, string $key): mixed
    {
        return match (true) {
            is_array($subject) => $subject[$key] ?? null,
            is_object($subject) => get_object_vars($subject)[$key] ?? null,
            default => null,
        };
    }

    /**
     * Whether the subject has every segment of `$path`, as `has()`: a plain subject has no relation of its own.
     *
     * @param non-empty-list<string> $path
     */
    public function loaded(mixed $subject, array $path): bool
    {
        return $this->has($subject, $path);
    }

    /**
     * The subject holds `$key`, checked in the order `key()` reads it: offset of an array, public property, `__isset()`,
     * `ArrayAccess`. Never reads the key itself.
     */
    private function holds(mixed $subject, string $key): bool
    {
        return match (true) {
            is_array($subject) => array_key_exists($key, $subject),
            !is_object($subject) => false,
            array_key_exists($key, get_object_vars($subject)) => true,
            method_exists($subject, '__isset') => $subject->__isset($key) === true,
            $subject instanceof ArrayAccess => $subject->offsetExists($key),
            default => false,
        };
    }

    private function key(mixed $subject, string $key): mixed
    {
        if (is_array($subject)) {
            return $subject[$key] ?? null;
        }
        if (!is_object($subject)) {
            return null;
        }
        $properties = get_object_vars($subject);
        if (array_key_exists($key, $properties)) {
            return $properties[$key];
        }
        if (method_exists($subject, '__get')) {
            return $subject->__get($key);
        }
        if ($subject instanceof ArrayAccess) {
            return $subject->offsetExists($key) ? $subject->offsetGet($key) : null;
        }

        return null;
    }
}
