<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion;

/**
 * The explicit casts (model.md §5), for a `down:` / `up:` whose automatic cast is not the one the clients need
 * (`float → int` truncates; `Cast::round()` rounds): `->was(Rev::X, schema: Schema::int(), down: Cast::round())`.
 */
final class Cast
{
    private function __construct() {}

    /**
     * float → int, rounded half away from zero (PHP `round()`: `2.5` → `3`, `-2.5` → `-3`).
     */
    public static function round(): Rounding
    {
        return new Rounding('round');
    }

    /**
     * float → int, rounded toward negative infinity.
     */
    public static function floor(): Rounding
    {
        return new Rounding('floor');
    }

    /**
     * float → int, rounded toward positive infinity.
     */
    public static function ceil(): Rounding
    {
        return new Rounding('ceil');
    }
}
