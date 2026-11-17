<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * date → date-time: midnight of that day, in the timezone of the value (model.md D90).
 */
final class DateToDateTime implements Converter
{
    /**
     * A value of another type (never a normalized one) is returned as it is.
     */
    public function convert(mixed $value, mixed $subject = null): mixed
    {
        return $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value)->setTime(0, 0) : $value;
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
