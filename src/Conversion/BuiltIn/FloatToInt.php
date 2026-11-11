<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Support\Floats;

/**
 * float → int, the PHP cast (truncated toward zero): lossy. A float outside the int range throws
 * `ConversionException` (model.md D44).
 */
final class FloatToInt implements Converter
{
    /**
     * A value of another type (never a normalized one) is returned as it is.
     */
    public function convert(mixed $value, mixed $subject = null): mixed
    {
        return is_float($value) ? Floats::toInt($value) ?? throw ConversionException::outOfIntRange($value) : $value;
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
