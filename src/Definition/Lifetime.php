<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Definition;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;

/**
 * The revisions a field exists in: from `since` included to `until` included (model.md §4.1, D1); `null` is unbounded.
 */
final readonly class Lifetime
{
    private function __construct(
        public ?Revision $since,
        public ?Revision $until,
    ) {}

    /**
     * Every revision.
     */
    public static function always(): self
    {
        return new self(null, null);
    }

    /**
     * From `$since` included, unbounded after.
     */
    public static function since(Revision $since): self
    {
        return new self($since, null);
    }

    /**
     * Up to `$until` included, unbounded before.
     */
    public static function until(Revision $until): self
    {
        return new self(null, $until);
    }

    /**
     * From `$since` included; `DefinitionException::invertedLifetime()` when newer than `until`,
     * `DefinitionException::mixedRevisions()` when of another enum.
     */
    public function withSince(?Revision $since): self
    {
        return self::bounded($since, $this->until);
    }

    /**
     * Up to `$until` included; same checks as `withSince()`.
     */
    public function withUntil(?Revision $until): self
    {
        return self::bounded($this->since, $until);
    }

    /**
     * Whether the field exists at `$revision`: at or after `since`, at or before `until`.
     */
    public function covers(Revision $revision): bool
    {
        return ($this->since === null || RevisionOrder::compare($revision, $this->since) >= 0)
            && ($this->until === null || RevisionOrder::compare($revision, $this->until) <= 0);
    }

    private static function bounded(?Revision $since, ?Revision $until): self
    {
        if ($since !== null && $until !== null && RevisionOrder::compare($since, $until) > 0) {
            throw DefinitionException::invertedLifetime($since, $until);
        }

        return new self($since, $until);
    }
}
