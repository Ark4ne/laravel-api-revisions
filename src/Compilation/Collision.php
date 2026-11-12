<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Compilation;

/**
 * Two or more fields of one shape written under the same name at its revision (model.md §4.4 rule 4): recorded on the
 * `Shape`, never thrown, reported by the check (US 9.2).
 */
final readonly class Collision
{
    /**
     * @param list<string> $headKeys the head keys of the colliding fields, in head order
     *
     * @internal built by `ShapeSelector`
     */
    public function __construct(
        public string $name,
        public array $headKeys,
    ) {}
}
