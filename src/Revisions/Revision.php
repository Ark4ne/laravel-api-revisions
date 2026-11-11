<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Revisions;

use BackedEnum;
use Closure;

/**
 * A revision of the API: a case of an application enum using `IsRevision`. Revisions are compared by their index in
 * `order()`, never by their declaration order nor their backed value. The backed value is what clients send.
 */
interface Revision extends BackedEnum
{
    /**
     * Every case exactly once, oldest first; a new revision is appended. The last one is the head.
     *
     * @return list<static>
     */
    public static function order(): array;

    public function isBefore(self $other): bool;

    public function isAfter(self $other): bool;

    public function isAtLeast(self $other): bool;

    public function isAtMost(self $other): bool;

    /**
     * The revision just before this one, `null` for the oldest.
     */
    public function previous(): ?static;

    /**
     * The revision just after this one, `null` for the head.
     */
    public function next(): ?static;

    /**
     * The `#[Meta]` of the case, an empty `Meta` when absent.
     */
    public function meta(): Meta;

    /**
     * The revision of the current context: the innermost `using()`, else the revision of the request, else the head.
     */
    public static function current(): static;

    /**
     * Runs `$callback` with `$revision` (a case of this enum) as the current revision, then restores the previous one,
     * even when it throws. The revision does not outlive the callback: render inside it, not in a resource or
     * generator returned by it.
     *
     * @template TResult
     *
     * @param Closure(): TResult $callback
     *
     * @return TResult
     */
    public static function using(self $revision, Closure $callback): mixed;

    /**
     * The head: the last revision of `order()`.
     */
    public static function latest(): static;

    /**
     * The first revision of `order()`.
     */
    public static function oldest(): static;
}
