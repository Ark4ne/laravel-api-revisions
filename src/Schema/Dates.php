<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;

/**
 * The normalization shared by `DateSchema` and `DateTimeSchema`.
 *
 * @internal
 */
final class Dates
{
    /**
     * A `DateTimeImmutable` from a `DateTimeInterface`, a string `DateTimeImmutable` parses without warning and without
     * surrounding blanks (in the default timezone unless it names one; relative strings like `tomorrow` included), or
     * an int timestamp (UTC); `null` for anything else.
     */
    public static function normalize(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value)) {
            return new DateTimeImmutable("@{$value}");
        }
        if (!is_string($value) || $value === '' || trim($value) !== $value) {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
        // An impossible date (`2026-02-30`) parses with a warning and rolls over: refused, never altered.
        $errors = DateTimeImmutable::getLastErrors();

        return $errors === false || $errors['warning_count'] === 0 ? $date : null;
    }
}
