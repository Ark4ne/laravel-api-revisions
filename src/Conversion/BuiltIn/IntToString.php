<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;

/**
 * int → string, its decimal digits.
 */
final class IntToString implements Converter
{
    /**
     * A value of another type (never a normalized one) is returned as it is.
     */
    public function convert(mixed $value): mixed
    {
        return is_int($value) ? (string) $value : $value;
    }

    public function lossless(): bool
    {
        return true;
    }

    public function extraRules(): array
    {
        return [];
    }
}
