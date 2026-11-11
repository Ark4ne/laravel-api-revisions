<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use RuntimeException;

/**
 * A value that cannot be converted at run time (an unknown enum value). The Laravel layer turns it into a validation
 * error on the request side. Named constructors only.
 */
final class ConversionException extends RuntimeException implements RevisionsException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param class-string $enum
     */
    public static function invalidEnumValue(string $enum, mixed $value): self
    {
        return new self(sprintf('%s is not a value of the enum %s.', var_export($value, true), $enum));
    }
}
