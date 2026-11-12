<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Compilation;

use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * The shape of a field up to one `was()` revision included, every omitted argument inherited from the next newer
 * shape (model.md §4.2): what the upgrader renames from on the step `revision → revision->next()`.
 */
final readonly class FieldVersion
{
    /**
     * @param int $change the index of the `was()` in the field's history, newest first
     *
     * @internal built by `ShapeSelector`
     */
    public function __construct(
        public Revision $revision,
        public int $change,
        public string $name,
        public TypeDefinition $type,
    ) {}
}
