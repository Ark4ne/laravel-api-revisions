<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Compilation;

use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;

/**
 * The fields of a class (or of an object's children) at one revision, in one direction (model.md §4.5, D27): built
 * only by the `ShapeSelector`, read by the projector, the upgrader, the rules, the export, the check and the lock.
 * Immutable, closure-free, serializable (model.md D45).
 */
final readonly class Shape
{
    /**
     * @param array<string, FieldDefinition> $fields the fields visible at `$revision`, by head key, in head order
     * @param array<string, FieldDefinition> $added up only: the fields added after `$revision`, by head key, each at
     *                                              its `since` revision, where its default is written (model.md D23)
     * @param list<Collision> $collisions the names written by more than one visible field
     * @param class-string|null $class the declaring class, set by the `Compiler` (`null` for an object's children)
     * @param DeclarationKind|null $kind the declaration compiled, set by the `Compiler`
     *
     * @internal built by `ShapeSelector`
     */
    public function __construct(
        public Revision $revision,
        public Direction $direction,
        public array $fields,
        public array $added = [],
        public array $collisions = [],
        public ?string $class = null,
        public ?DeclarationKind $kind = null,
    ) {}

    /**
     * This shape naming the declaration it was compiled from.
     *
     * @param class-string $class
     *
     * @internal called by `Compiler`
     */
    public function of(string $class, DeclarationKind $kind): self
    {
        return new self($this->revision, $this->direction, $this->fields, $this->added, $this->collisions, $class, $kind);
    }

    /**
     * The names of the visible fields at the revision, in head order (a colliding name once per field).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_map(static fn(FieldDefinition $field): string => $field->name, $this->fields));
    }

    /**
     * The visible field of head key `$headKey`, `null` when it is not visible at the revision.
     */
    public function field(string $headKey): ?FieldDefinition
    {
        return $this->fields[$headKey] ?? null;
    }
}
