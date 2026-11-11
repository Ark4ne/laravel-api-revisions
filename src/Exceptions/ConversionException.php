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

    /**
     * A step of a conversion chain without converter: no automatic conversion and no `down:` / `up:` (check code
     * `conversion-missing`), or a chain applied before `bind()`.
     */
    public static function missing(string $key): self
    {
        return new self(sprintf('No conversion %s: declare down: / up: on the was(), or register a converter.', $key));
    }

    /**
     * A float → int conversion of a float the int range cannot hold (beyond ±2^63, `INF`, `NaN`, model.md D44).
     */
    public static function outOfIntRange(float $value): self
    {
        return new self(sprintf('%s is outside the int range.', var_export($value, true)));
    }
}
