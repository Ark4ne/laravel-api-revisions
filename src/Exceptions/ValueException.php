<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use BackedEnum;
use RuntimeException;

/**
 * A value of the subject the projection cannot render (model.md §6.3): refused by its type, `null` on a non-nullable
 * field, or failing a conversion. Names the class, the field and the revision, and the value's type, never the value
 * itself. Named constructors only.
 */
final class ValueException extends RuntimeException implements RevisionsException
{
    /**
     * @param string $class the class declaring the field (`object` for a shape without class)
     * @param string $field its head key (`*` for an item)
     */
    private function __construct(
        public readonly string $class,
        public readonly string $field,
        public readonly BackedEnum $revision,
        string $reason,
    ) {
        parent::__construct(sprintf('%s field %s at %s::%s: %s', $class, $field, $revision::class, $revision->name, $reason));
    }

    /**
     * A value its type refuses (`Descriptor::normalize()` gave an `InvalidValue`).
     */
    public static function refused(string $class, string $field, BackedEnum $revision, string $expected, string $type): self
    {
        return new self($class, $field, $revision, "{$expected} expected, {$type} given.");
    }

    /**
     * `null` on a field (or item) that is not nullable at the revision.
     */
    public static function nullNotAllowed(string $class, string $field, BackedEnum $revision): self
    {
        return new self($class, $field, $revision, 'null on a non-nullable field.');
    }

    /**
     * A conversion of the down chain that failed (a `ConversionException`, not chained: its message holds the value).
     */
    public static function unconvertible(string $class, string $field, BackedEnum $revision, string $type, string $expected): self
    {
        return new self($class, $field, $revision, "{$type} cannot be converted to {$expected}.");
    }
}
