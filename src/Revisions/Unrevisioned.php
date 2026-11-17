<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Revisions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Closure;

/**
 * The one revision of an application that configures no revision enum (`revisions.enum` left `null`):
 * its head, so resources, requests and commands work through the library with no history possible. The package uses
 * no trait (docs/architecture.md §2): the methods of `IsRevision` are written out.
 */
enum Unrevisioned: string implements Revision
{
    case Head = 'head';

    public static function order(): array
    {
        return [self::Head];
    }

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

    public function previous(): ?static
    {
        return null;
    }

    public function next(): ?static
    {
        return null;
    }

    public function meta(): Meta
    {
        return MetaReader::of($this);
    }

    public static function current(): static
    {
        return RevisionScope::get()->current(self::class);
    }

    /**
     * @template TResult
     *
     * @param Closure(): TResult $callback
     *
     * @return TResult
     */
    public static function using(Revision $revision, Closure $callback): mixed
    {
        if (!$revision instanceof self) {
            throw DefinitionException::foreignRevision($revision, self::class);
        }

        return RevisionScope::get()->using($revision, $callback);
    }

    public static function latest(): static
    {
        return self::Head;
    }

    public static function oldest(): static
    {
        return self::Head;
    }
}
