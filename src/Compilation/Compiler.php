<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Compilation;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\ClassLifetime;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;

/**
 * Compiles a declaring class into the shape of one revision (docs/architecture.md §7): builds the class on a `Probe`,
 * reads its head descriptors, resolves the configured defaults (`Descriptor::configured()`) and calls
 * the `ShapeSelector` with the direction of the declaration kind. Runs no source.
 */
final readonly class Compiler
{
    /**
     * @param Converters|null $converters the registry the chains are planned against; `TypeRegistry::converters()`
     *                                    (the built-in one with the registered custom types) at each compilation by
     *                                    default
     */
    public function __construct(
        private Configuration $configuration = new Configuration(),
        private ?Converters $converters = null,
    ) {}

    /**
     * The shape of the declaration `$kind` of `$class` at `$revision`, naming its class and kind.
     * `DefinitionException::notRevisioned()` for a class not implementing `Revisioned`, `undeclaredKind()` for a kind
     * it does not declare, `invalidDeclaration()` for an entry that is not a descriptor under a string key,
     * `subjectReadAtCompilation()` when the declaration reads its subject, `classAbsent()` at a revision the lifetime
     * of the class (`HasLifetime`) does not cover, `nestedAbsent()` for a visible field nesting a class
     * absent at `$revision`.
     *
     * @param class-string $class
     */
    public function compile(string $class, DeclarationKind $kind, Revision $revision): Shape
    {
        $descriptors = $this->describe($class, $kind);
        if (!ClassLifetime::exists($class, $revision)) {
            throw DefinitionException::classAbsent($class, $revision);
        }
        $shape = ShapeSelector::at(
            $descriptors,
            $revision,
            $kind->direction(),
            $this->converters(),
            $this->configuration,
        )->of($class, $kind);
        foreach ([...$shape->fields, ...$shape->added] as $field) {
            self::checkNested($class, $field->type, $kind, $revision);
        }

        return $shape;
    }

    /**
     * The head descriptors of the declaration `$kind` of `$class`, configured, built on a `Probe`; the same exceptions as
     * `compile()`, but those of the nested resources, checked per revision.
     *
     * @param class-string $class
     *
     * @return array<string, Descriptor<mixed>>
     */
    public function describe(string $class, DeclarationKind $kind): array
    {
        if (!is_subclass_of($class, Revisioned::class)) {
            throw DefinitionException::notRevisioned($class);
        }
        if (!in_array($kind, $class::declarationKinds(), true)) {
            throw DefinitionException::undeclaredKind($class, $kind->value);
        }
        $head = [];
        foreach ($class::describeRevisioned($kind, new Probe($class)) as $key => $descriptor) {
            // @phpstan-ignore function.alreadyNarrowedType, instanceof.alwaysTrue, booleanOr.alwaysFalse (checked at run time: declarations are application code)
            if (!is_string($key) || !$descriptor instanceof Descriptor) {
                throw DefinitionException::invalidDeclaration($class, $key, get_debug_type($descriptor));
            }
            $head[$key] = $descriptor->configured($this->configuration);
        }

        return $head;
    }

    /**
     * The registry the chains are planned against: the one given, else `TypeRegistry::converters()`.
     */
    public function converters(): Converters
    {
        return $this->converters ?? TypeRegistry::converters();
    }

    /**
     * Every resource nested in `$type` (its items and children included) declares `$kind`: its shape is the one of the
     * same declaration kind and exists at `$revision`; a type of a custom descriptor may
     * reference any class, so it is `Revisioned` too. Checked on the reference only: the nested class is not compiled
     * here, so cycles compile.
     *
     * @param class-string $owner
     */
    private static function checkNested(string $owner, TypeDefinition $type, DeclarationKind $kind, Revision $revision): void
    {
        $class = $type->resource;
        if ($class !== null) {
            if (!is_subclass_of($class, Revisioned::class)) {
                throw DefinitionException::notRevisioned($class);
            }
            if (!in_array($kind, $class::declarationKinds(), true)) {
                throw DefinitionException::undeclaredKind($class, $kind->value);
            }
            if (!ClassLifetime::exists($class, $revision)) {
                throw DefinitionException::nestedAbsent($owner, $class, $revision);
            }
        }
        foreach ([$type->items, ...$type->children] as $nested) {
            if ($nested !== null) {
                self::checkNested($owner, $nested, $kind, $revision);
            }
        }
    }
}
