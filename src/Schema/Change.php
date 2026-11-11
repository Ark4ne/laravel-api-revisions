<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Revisions\Revision;
use Closure;

/**
 * One `was()`: up to `$revision` included, the field differed from the next newer shape (model.md §4.2). An argument
 * left `null` keeps the value of that newer shape. Never a source: older values derive from the head value (§6.4).
 * Built by `Descriptor::was()`, which checks its arguments; read by the compiler.
 *
 * @internal
 */
final readonly class Change
{
    /**
     * @param non-empty-string|null $name the key at `$revision` and older, unless an older change renames it again
     * @param Descriptor<mixed>|null $schema the type at `$revision` and older (its source is never read)
     * @param Closure|null $down `($value, $subject)`: the value of the next newer shape → the value of this one
     * @param Closure|null $up `($value, $subject)`: the value of this shape → the value of the next newer one
     */
    public function __construct(
        public Revision $revision,
        public ?string $name = null,
        public ?Descriptor $schema = null,
        public ?Closure $down = null,
        public ?Closure $up = null,
    ) {}
}
