<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\ClassLifetime;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;

/**
 * The discovered declaring classes with their declaration kinds: what a generator reads to know which
 * documents to export. Public contract, under semver from 1.0 (architecture.md §4).
 */
final readonly class Declarations
{
    public function __construct(private ClassFinder $finder) {}

    /**
     * Every class the finder gives, in its order, with its declaration kinds.
     *
     * @return array<class-string<Revisioned>, non-empty-list<DeclarationKind>>
     */
    public function declarations(): array
    {
        $declarations = [];
        foreach ($this->finder->find() as $class) {
            $declarations[$class] = $class::declarationKinds();
        }

        return $declarations;
    }

    /**
     * The classes existing at `$revision`, in the finder's order, with their declaration kinds.
     *
     * @return array<class-string<Revisioned>, non-empty-list<DeclarationKind>>
     */
    public function at(Revision $revision): array
    {
        return array_filter($this->declarations(), static fn(string $class): bool => ClassLifetime::exists($class, $revision), ARRAY_FILTER_USE_KEY);
    }
}
