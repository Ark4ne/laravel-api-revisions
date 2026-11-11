<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use ReflectionClass;

/**
 * The custom types served by `Schema::__callStatic()` (`Schema::money()`), registered by name at boot (model.md §3,
 * D42). Static on purpose, like Laravel's macros: one of the allowed global states (docs/architecture.md §16);
 * `flush()` empties it (tests).
 */
final class TypeRegistry
{
    /**
     * @var array<string, class-string<Descriptor<mixed>>>
     */
    private static array $types = [];

    /**
     * Registers (or replaces) a custom type. `$name` is a PHP method name that `Schema` does not already declare
     * (`DefinitionException::invalidTypeName()`), case-sensitive like Laravel's macros (`Schema::Money()` is not
     * `money`); `$class` is a concrete class extending `Descriptor` (`DefinitionException::invalidCustomType()`). The
     * name is the factory's; the type's own name is the one its `TypeDefinition::custom()` declares.
     *
     * @param class-string<Descriptor<mixed>> $class
     */
    public static function register(string $name, string $class): void
    {
        if (preg_match('/^[a-z_]\w*$/iD', $name) !== 1 || method_exists(Schema::class, $name)) {
            throw DefinitionException::invalidTypeName($name);
        }
        // @phpstan-ignore function.alreadyNarrowedType (checked at run time: registrations are application code)
        if (!is_subclass_of($class, Descriptor::class) || (new ReflectionClass($class))->isAbstract()) {
            throw DefinitionException::invalidCustomType($name, $class);
        }
        self::$types[$name] = $class;
    }

    public static function has(string $name): bool
    {
        return isset(self::$types[$name]);
    }

    /**
     * A new descriptor of the type, built with `$arguments` (`DefinitionException::unknownType()` when not registered).
     * Arguments the constructor refuses raise PHP's own `ArgumentCountError` / `Error`, as for any constructor.
     *
     * @param array<array-key, mixed> $arguments the constructor arguments, positional or named
     *
     * @return Descriptor<mixed>
     */
    public static function make(string $name, array $arguments): Descriptor
    {
        $class = self::$types[$name] ?? throw DefinitionException::unknownType($name);

        return new $class(...$arguments);
    }

    /**
     * Forgets every registered type.
     */
    public static function flush(): void
    {
        self::$types = [];
    }
}
