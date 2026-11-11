<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Revisions;

/**
 * Behaviour of an application's revision enum, delegating to `RevisionOrder`. Declared for applications, used by no
 * class of the package.
 *
 * @phpstan-require-implements Revision
 */
trait IsRevision
{
    public function isBefore(Revision $other): bool
    {
        return RevisionOrder::compare($this, $other) < 0;
    }

    public function isAfter(Revision $other): bool
    {
        return RevisionOrder::compare($this, $other) > 0;
    }

    public function isAtLeast(Revision $other): bool
    {
        return RevisionOrder::compare($this, $other) >= 0;
    }

    public function isAtMost(Revision $other): bool
    {
        return RevisionOrder::compare($this, $other) <= 0;
    }

    /**
     * @return self|null `self`, not `static`: on an enum, PHPStan reads `static` as the case itself
     */
    public function previous(): ?static
    {
        /** @var self|null */
        return RevisionOrder::previous($this);
    }

    /**
     * @return self|null
     */
    public function next(): ?static
    {
        /** @var self|null */
        return RevisionOrder::next($this);
    }

    public function meta(): Meta
    {
        return MetaReader::of($this);
    }

    public static function latest(): static
    {
        return RevisionOrder::latest(static::class);
    }

    public static function oldest(): static
    {
        return RevisionOrder::oldest(static::class);
    }
}
