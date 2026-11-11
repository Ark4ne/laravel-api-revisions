<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Revisions;

use BackedEnum;

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
     * The head: the last revision of `order()`.
     */
    public static function latest(): static;

    /**
     * The first revision of `order()`.
     */
    public static function oldest(): static;
}
