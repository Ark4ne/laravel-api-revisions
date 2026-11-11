<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;

/**
 * string → float, the PHP cast: lossy; up, the older shape requires `numeric`.
 */
final class NumericStringToFloat implements Converter
{
    /**
     * A value of another type (never a normalized one) is returned as it is.
     */
    public function convert(mixed $value): mixed
    {
        return is_string($value) ? (float) $value : $value;
    }

    public function lossless(): bool
    {
        return false;
    }

    public function extraRules(): array
    {
        return ['numeric'];
    }
}
