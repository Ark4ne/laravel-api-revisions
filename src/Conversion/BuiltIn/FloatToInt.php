<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;

/**
 * float → int, the PHP cast (truncated toward zero): lossy.
 */
final class FloatToInt implements Converter
{
    /**
     * A value of another type (never a normalized one) is returned as it is.
     */
    public function convert(mixed $value): mixed
    {
        return is_float($value) ? (int) $value : $value;
    }

    public function lossless(): bool
    {
        return false;
    }

    public function extraRules(): array
    {
        return [];
    }
}
