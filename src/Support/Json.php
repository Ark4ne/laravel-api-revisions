<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Support;

/**
 * @internal
 */
final class Json
{
    /**
     * Scalars, nulls and arrays of them, at any depth: what a definition may hold (serializable, closure-free).
     */
    public static function isReady(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isReady($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }
}
