<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;

/**
 * bool → string: `'1'` / `'0'` (model.md D40).
 */
final class BoolToString implements Converter
{
    /**
     * A value of another type (never a normalized one) is returned as it is.
     */
    public function convert(mixed $value, mixed $subject = null): mixed
    {
        return is_bool($value) ? ($value ? '1' : '0') : $value;
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
