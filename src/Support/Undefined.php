<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Support;

/**
 * "No value": tells a missing default from a default `null` (model.md §7.3). A plain value object, not a singleton:
 * compare with `Undefined::is()`, never with `===`.
 */
final readonly class Undefined
{
    public static function is(mixed $value): bool
    {
        return $value instanceof self;
    }
}
