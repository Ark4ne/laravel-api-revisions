<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;

/**
 * date-time → date: the same instant; the date serializer writes its day in the timezone of the value, never shifted
 * to UTC (model.md D90). Lossless down (a response falls back to the day the older shape asks for), lossy up (the time
 * a client sent is dropped: `conversion-lossy`).
 */
final class DateTimeToDate implements Converter
{
    public function __construct(private bool $lossless = true) {}

    /**
     * The value as it is: a date and a date-time are both a `DateTimeImmutable`.
     */
    public function convert(mixed $value, mixed $subject = null): mixed
    {
        return $value;
    }

    public function lossless(): bool
    {
        return $this->lossless;
    }

    public function extraRules(): array
    {
        return [];
    }
}
