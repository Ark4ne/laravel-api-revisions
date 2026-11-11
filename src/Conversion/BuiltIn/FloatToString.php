<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;
use Ark4ne\ApiRevisions\Support\Floats;

/**
 * float → string, its shortest round-trip writing (`0.1` → `'0.1'`).
 */
final class FloatToString implements Converter
{
    /**
     * A value of another type (never a normalized one) is returned as it is.
     */
    public function convert(mixed $value): mixed
    {
        return is_float($value) ? Floats::toString($value) : $value;
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
