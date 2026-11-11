<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;

/**
 * string → int, the PHP cast (`'12.7'` → `12`, `'abc'` → `0`): lossy; up, the older shape requires `integer`.
 */
final class NumericStringToInt implements Converter
{
    /**
     * A value of another type (never a normalized one) is returned as it is.
     */
    public function convert(mixed $value): mixed
    {
        return is_string($value) ? (int) $value : $value;
    }

    public function lossless(): bool
    {
        return false;
    }

    public function extraRules(): array
    {
        return ['integer'];
    }
}
