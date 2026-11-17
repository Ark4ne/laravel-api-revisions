<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Compilation;

use Ark4ne\ApiRevisions\Conversion\ConversionChain;
use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;

/**
 * One field of a class at one revision, decided by the `ShapeSelector`: its name and type at that
 * revision, its presence, and its conversion chain in the direction of the shape. Immutable, closure-free (the chain is
 * unbound), serializable: the closures and the rules are re-read from the fresh descriptors through the references it
 * keeps (`schemaChange`, the `change` of each step).
 *
 * The value at the revision (type, `nullable`) comes from the descriptor in force there, the head or the schema of a
 * `was()`; the presence (`required`, `sometimes`, `conditional`, `default`, `lifetime`) from the head.
 */
final readonly class FieldDefinition
{
    /**
     * @param string $headKey its key at the head (`*` for the items of a list)
     * @param string $name its key at `$revision`
     * @param TypeDefinition $type its type at `$revision`: the children and items of a composite at `$revision` too
     * @param mixed $default the default of its `since()`, an `Undefined` without one
     * @param int|null $schemaChange the index of the `was()` whose schema is in force at `$revision`, `null` for the head
     * @param list<FieldVersion> $versions its shape at every `was()` from the head down to `$revision`, newest first
     * @param ConversionChain $chain from the head to `$revision` (down), from `$revision` to the head (up)
     * @param FieldDefinition|null $items the items of a head list at `$headAt`, whatever schema a `was()` puts in force
     *                                  (that one is `$type`)
     * @param Shape|null $children the children of a head object at `$headAt`, whatever schema a `was()` puts in force
     * @param Revision|null $headAt where the head descriptor is still in force: `$revision`, or the revision after its
     *                              newest `was(schema:)` at or after `$revision`; its items, children and nested
     *                              resource are read there, the conversions of the `was()` start from there
     *
     * @internal built by `ShapeSelector`
     */
    public function __construct(
        public string $headKey,
        public Revision $revision,
        public string $name,
        public TypeDefinition $type,
        public bool $nullable,
        public bool $required,
        public bool $sometimes,
        public bool $conditional,
        public mixed $default,
        public Lifetime $lifetime,
        public ?int $schemaChange,
        public array $versions,
        public ConversionChain $chain,
        public ?FieldDefinition $items = null,
        public ?Shape $children = null,
        ?Revision $headAt = null,
    ) {
        $this->headAt = $headAt ?? $revision;
    }

    public Revision $headAt;

    /**
     * Its shape up to the `was()` of `$revision` included, `null` when it has none there (nothing changes on the step
     * `$revision → $revision->next()`).
     */
    /**
     * Its name at `$revision` (at or after `$this->revision`): that of its oldest `was()` at or after `$revision`, else
     * its head key.
     */
    public function nameAt(Revision $revision): string
    {
        $name = $this->headKey;
        foreach ($this->versions as $version) {
            if (RevisionOrder::compare($version->revision, $revision) < 0) {
                break;
            }
            $name = $version->name;
        }

        return $name;
    }

    public function versionAt(Revision $revision): ?FieldVersion
    {
        foreach ($this->versions as $version) {
            if ($version->revision === $revision) {
                return $version;
            }
        }

        return null;
    }
}
