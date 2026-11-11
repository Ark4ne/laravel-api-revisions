<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Support;

/**
 * @internal
 */
final class Floats
{
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
