<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Compilation;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;

/**
 * Compiles a declaring class into the shape of one revision (docs/architecture.md §7): builds the class on a `Probe`,
 * reads its head descriptors, resolves the configured defaults (`Descriptor::configured()`, model.md D29) and calls
 * the `ShapeSelector` with the direction of the declaration kind (D27). Runs no source.
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
     * `subjectReadAtCompilation()` when the declaration reads its subject.
     *
     * @param class-string $class
     */
    public function compile(string $class, DeclarationKind $kind, Revision $revision): Shape
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

        return ShapeSelector::at(
            $head,
            $revision,
            $kind->direction(),
            $this->converters ?? TypeRegistry::converters(),
            $this->configuration,
        )->of($class, $kind);
    }
}
