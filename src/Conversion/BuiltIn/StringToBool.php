<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;

/**
 * string → bool as `filter_var(FILTER_VALIDATE_BOOLEAN)`, any other string `true`: lossy.
 */
final class StringToBool implements Converter
{
    /**
     * A value of another type (never a normalized one) is returned as it is.
     */
    public function convert(mixed $value, mixed $subject = null): mixed
    {
        return is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true : $value;
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
