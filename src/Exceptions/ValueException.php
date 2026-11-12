<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use BackedEnum;
use RuntimeException;

/**
 * A value of the subject the projection cannot render (model.md §6.3): refused by its type, or failing a conversion.
 * Names the class, the field and the revision, and the value's type, never the value itself. Named constructors only.
 */
final class ValueException extends RuntimeException implements RevisionsException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * A value its type refuses (`Descriptor::normalize()` gave an `InvalidValue`).
     */
    public static function refused(string $class, string $field, BackedEnum $revision, string $expected, string $type): self
    {
        return new self(sprintf('%s: %s expected, %s given.', self::at($class, $field, $revision), $expected, $type));
    }

    /**
     * A conversion of the down chain that failed (a `ConversionException`, not chained: its message holds the value).
     */
    public static function unconvertible(string $class, string $field, BackedEnum $revision, string $type, string $expected): self
    {
        return new self(sprintf('%s: %s cannot be converted to %s.', self::at($class, $field, $revision), $type, $expected));
    }

    private static function at(string $class, string $field, BackedEnum $revision): string
    {
        return sprintf('%s field %s at %s::%s', $class, $field, $revision::class, $revision->name);
    }
}
