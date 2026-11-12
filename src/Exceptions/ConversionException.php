<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use RuntimeException;

/**
 * A value that cannot be converted at run time (an unknown enum value, an input a closure rejects). On the request side
 * the upgrader wraps it in an `InvalidInputException`, a validation error on the client's key (model.md D63), except
 * `missing()`, a declaration error. Named constructors only.
 */
final class ConversionException extends RuntimeException implements RevisionsException
{
    /**
     * @param string|null $reason the message for the client, given by `invalid()`
     * @param bool $declarationError a conversion that is missing from the declaration, not a value rejected
     */
    private function __construct(
        string $message,
        public readonly ?string $reason = null,
        public readonly bool $declarationError = false,
    ) {
        parent::__construct($message);
    }

    /**
     * An input an `up:` (or a value a `down:`) closure rejects for its revision: on the request side, `$message` is the
     * validation error on the client's key (model.md D63).
     */
    public static function invalid(string $message): self
    {
        return new self($message, $message);
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
        return new self(sprintf('No conversion %s: declare down: / up: on the was(), or register a converter.', $key), declarationError: true);
    }

    /**
     * A float → int conversion of a float the int range cannot hold (beyond ±2^63, `INF`, `NaN`, model.md D44).
     */
    public static function outOfIntRange(float $value): self
    {
        return new self(sprintf('%s is outside the int range.', var_export($value, true)));
    }
}
