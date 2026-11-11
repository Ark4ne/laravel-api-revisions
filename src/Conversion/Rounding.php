<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion;

use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Support\Floats;

/**
 * float → int by `round()`, `floor()` or `ceil()` (built by `Cast`). An int, or any value of another type, passes
 * through unchanged; a float outside the int range throws `ConversionException` (model.md D44). Explicit and lossy.
 */
final readonly class Rounding implements ExplicitConverter
{
    /**
     * @param 'round'|'floor'|'ceil' $mode
     *
     * @internal built by `Cast`
     */
    public function __construct(public string $mode) {}

    /**
     * A value of another type than float is returned as it is.
     */
    public function convert(mixed $value, mixed $subject = null): mixed
    {
        if (!is_float($value)) {
            return $value;
        }
        $rounded = match ($this->mode) {
            'round' => round($value),
            'floor' => floor($value),
            'ceil' => ceil($value),
        };

        return Floats::toInt($rounded) ?? throw ConversionException::outOfIntRange($value);
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
