<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Projection;

use ArrayAccess;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

/**
 * The core `SourceReader` (model.md §6.2): arrays and `ArrayAccess` by offset, objects by public property (never
 * through `get_object_vars()`, model.md D88), then `__get()`. A key the subject does not have reads `null`; `null` in a
 * path stops it. The errors of the subject itself (a strict mode refusing a missing attribute) are never hidden: no
 * `isset()` before `__get()`. A relation is a key: `loaded()` is `has()`.
 */
final class PlainSourceReader implements SourceReader
{
    /**
     * The properties each class read declares (model.md D88): a public non-static one by its reflection, any other as
     * `false`. Per instance: it depends only on the code, and the reader is built once per projector.
     *
     * @var array<class-string, array<string, ReflectionProperty|false>>
     */
    private array $declared = [];

    /**
     * Whether `property_exists()` tells the dynamic properties of a class (no internal parent but `stdClass`, whose
     * own handlers may answer otherwise: `SimpleXMLElement`, `ArrayObject`…), per class.
     *
     * @var array<class-string, bool>
     */
    private array $plain = [];

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
            is_object($subject) => $this->property($subject, $key, $value) ? $value : null,
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
            $this->property($subject, $key, $value) => true,
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
        if ($this->property($subject, $key, $value)) {
            return $value;
        }
        if (method_exists($subject, '__get')) {
            return $subject->__get($key);
        }
        if ($subject instanceof ArrayAccess) {
            return $subject->offsetExists($key) ? $subject->offsetGet($key) : null;
        }

        return null;
    }

    /**
     * Whether `$key` is a public property the subject holds, exactly as `get_object_vars()` from outside lists it, its
     * value set in `$value` (model.md D88). Without building the object's property table on the common paths: an
     * initialized declared public property, read directly; a name no class declares, through `property_exists()` on a
     * plain class. Every other case asks `get_object_vars()` (rare: a property not initialized, unset or lazy, a
     * non-public, static or virtual one, a class with an internal parent).
     */
    private function property(object $subject, string $key, mixed &$value): bool
    {
        $class = $subject::class;
        $declared = ($this->declared[$class] ??= self::declared($class))[$key] ?? null;
        if ($declared instanceof ReflectionProperty && $declared->isInitialized($subject)) {
            $value = $subject->{$key}; // @phpstan-ignore property.dynamicName (a public property, checked just above)

            return true;
        }
        if ($declared === null && ($this->plain[$class] ??= self::plain($class))) {
            if (!property_exists($subject, $key)) {
                return false;
            }
            $value = $subject->{$key}; // @phpstan-ignore property.dynamicName (a dynamic property, checked just above)

            return true;
        }
        $properties = get_object_vars($subject);
        if (!array_key_exists($key, $properties)) {
            return false;
        }
        $value = $properties[$key];

        return true;
    }

    /**
     * The properties `$class` and its parents declare: a public non-static concrete one by its reflection, any other
     * (static, protected, private, virtual) as `false`.
     *
     * @param class-string $class
     *
     * @return array<string, ReflectionProperty|false>
     */
    private static function declared(string $class): array
    {
        $declared = [];
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getProperties() as $property) {
                $concrete = $property->isPublic() && !$property->isStatic() && !self::isVirtual($property);
                $declared[$property->getName()] ??= $concrete ? $property : false;
            }
        }

        return $declared;
    }

    /**
     * A hooked property without storage (PHP 8.4), which `get_object_vars()` never lists.
     */
    private static function isVirtual(ReflectionProperty $property): bool
    {
        return PHP_VERSION_ID >= 80400 && $property->isVirtual();
    }

    /**
     * Whether `$class` has no internal parent but `stdClass`.
     *
     * @param class-string $class
     */
    private static function plain(string $class): bool
    {
        for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            if ($reflection->isInternal() && $reflection->getName() !== stdClass::class) {
                return false;
            }
        }

        return true;
    }
}
