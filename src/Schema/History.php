<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;

/**
 * The history of a field: its lifetime and its `was()`, ordered by revision, newest first. Immutable.
 * Built by `Descriptor` (`since()`, `until()`, `was()`), which checks the arguments of a `was()`.
 */
final readonly class History
{
    /**
     * @param list<Change> $changes newest first
     */
    private function __construct(
        public Lifetime $lifetime,
        public array $changes,
    ) {}

    /**
     * A field existing in every revision, without change.
     */
    public static function none(): self
    {
        return new self(Lifetime::always(), []);
    }

    /**
     * Every revision, no change: what `none()` gives.
     */
    public function isEmpty(): bool
    {
        return $this->lifetime->since === null && $this->lifetime->until === null && $this->changes === [];
    }

    public function withLifetime(Lifetime $lifetime): self
    {
        $history = new self($lifetime, $this->changes);
        $history->assertOneEnum();

        return $history;
    }

    /**
     * Adds a change at its place by revision. A change on the head or on a revision already changed throws
     * `DefinitionException::invalidHistory()`; a revision of another enum `DefinitionException::mixedRevisions()`.
     *
     * @internal called by `Descriptor::was()`, which checks the change itself
     */
    public function withChange(Change $change): self
    {
        if ($change->revision === RevisionOrder::latest($change->revision::class)) {
            throw DefinitionException::invalidHistory($change->revision, 'the head has no older shape');
        }
        // inserted before the first older change: declarations list them newest first, so it is usually appended
        $changes = [];
        $placed = false;
        foreach ($this->changes as $existing) {
            $order = RevisionOrder::compare($existing->revision, $change->revision);
            if ($order === 0) {
                throw DefinitionException::invalidHistory($change->revision, 'this revision already has a was()');
            }
            if (!$placed && $order < 0) {
                $changes[] = $change;
                $placed = true;
            }
            $changes[] = $existing;
        }
        if (!$placed) {
            $changes[] = $change;
        }
        $history = new self($this->lifetime, $changes);
        $history->assertOneEnum();

        return $history;
    }

    /**
     * This history with the schema of every `was()` configured (`Descriptor::configured()`).
     *
     * @internal called by `Descriptor::configured()`
     */
    public function configured(Configuration $configuration): self
    {
        return new self($this->lifetime, array_map(
            static fn(Change $change): Change => $change->schema === null ? $change : new Change(
                $change->revision,
                $change->name,
                $change->schema->configured($configuration),
                $change->down,
                $change->up,
            ),
            $this->changes,
        ));
    }

    /**
     * The bounds and the changes are revisions of one enum (`DefinitionException::mixedRevisions()`).
     */
    private function assertOneEnum(): void
    {
        $first = null;
        foreach ([$this->lifetime->since, $this->lifetime->until, $this->changes[0]->revision ?? null] as $revision) {
            if ($revision !== null) {
                $first === null ? $first = $revision : RevisionOrder::compare($first, $revision);
            }
        }
    }
}
