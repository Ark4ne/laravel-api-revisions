<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Closure;

/**
 * The fluent methods a consumer adds to every descriptor (`Schema::string()->whenInFields('title')`), registered by
 * name at boot and served by `Descriptor::__call()`. Static on purpose, like `TypeRegistry`: one of the
 * allowed global states (docs/architecture.md §16); the registrations carry no volatile data, `flush()` empties it for
 * tests only.
 */
final class DescriptorMacros
{
    /**
     * @var array<string, Closure>
     */
    private static array $macros = [];

    /**
     * Registers (or replaces) a macro. `$name` is a PHP method name that `Descriptor` does not declare
     * (`DefinitionException::invalidMacroName()`), case-sensitive; a public method of the subclass of that name wins
     * over it (a private or protected one does not, as for any `__call()`).
     * `$macro` is called `($descriptor, ...$arguments)`, unbound (the public modifiers only), and returns an instance
     * of the receiver's class (`Class::method(...)` for the method PHPStan maps in `apiRevisions.macros`).
     */
    public static function register(string $name, Closure $macro): void
    {
        if (preg_match('/^[a-z_]\w*$/iD', $name) !== 1 || method_exists(Descriptor::class, $name)) {
            throw DefinitionException::invalidMacroName($name);
        }
        self::$macros[$name] = $macro;
    }

    public static function has(string $name): bool
    {
        return isset(self::$macros[$name]);
    }

    /**
     * Runs the macro `$name` on `$descriptor` with `$arguments` (positional or named):
     * `DefinitionException::unknownMacro()` when not registered, `invalidMacroResult()` when it returns anything but an
     * instance of `$descriptor`'s class.
     *
     * @internal what `Descriptor::__call()` runs
     *
     * @template TDescriptor of Descriptor<mixed>
     *
     * @param TDescriptor $descriptor
     * @param array<array-key, mixed> $arguments
     *
     * @return TDescriptor
     */
    public static function call(Descriptor $descriptor, string $name, array $arguments): Descriptor
    {
        $macro = self::$macros[$name] ?? throw DefinitionException::unknownMacro($name, $descriptor::class);
        $result = $macro($descriptor, ...$arguments);
        if (!$result instanceof $descriptor) {
            throw DefinitionException::invalidMacroResult($name, $descriptor::class, get_debug_type($result));
        }

        return $result;
    }

    /**
     * Forgets every registered macro (tests).
     */
    public static function flush(): void
    {
        self::$macros = [];
    }
}
