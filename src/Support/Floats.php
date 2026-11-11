<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Support;

/**
 * @internal
 */
final class Floats
{
    /**
     * The int of a float, truncated toward zero, or `null` when the int range cannot hold it: outside [-2^63, 2^63),
     * `INF`, `NaN` (model.md D44). Every float that close to 2^63 is integral, so truncating first changes nothing.
     */
    public static function toInt(float $value): ?int
    {
        // 9.2233720368547758E18 is 2^63, exact as a float; NaN fails both comparisons.
        return $value >= -9.2233720368547758E18 && $value < 9.2233720368547758E18 ? (int) $value : null;
    }

    /**
     * The shortest string that reads back as the same float, whatever the `precision` setting (`0.1 + 0.2` keeps its
     * 17 digits, `0.1` stays `'0.1'`), in PHP's notation (`'12'`, `'1.0E+20'`). A finite float only.
     */
    public static function toString(float $value): string
    {
        $digits = 1;
        while ($digits < 17 && (float) sprintf("%.{$digits}G", $value) !== $value) {
            $digits++;
        }
        $written = sprintf("%.{$digits}G", $value);

        return preg_replace('/^(-?\d+)E/', '$1.0E', $written) ?? $written;
    }
}
