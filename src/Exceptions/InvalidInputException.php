<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use RuntimeException;

/**
 * An input value the upgrade rejects (model.md D63): a `ConversionException` of a converter or an `up:` closure, or a
 * `ValueException` (a value the rules let through and its type refuses). Thrown by the upgrader; the Laravel layer turns
 * it into a validation error on `$key`. Named constructors only.
 */
final class InvalidInputException extends RuntimeException implements RevisionsException
{
    /**
     * @param string $key the client's key: its names at the client's revision, dotted (`a.b`, `a.0`)
     * @param string|null $reason the message for the client (`ConversionException::invalid()`); `null` for a generic one
     */
    private function __construct(
        public readonly string $key,
        public readonly ?string $reason,
        ConversionException|ValueException $previous,
    ) {
        parent::__construct("The input {$key} is invalid: {$previous->getMessage()}", 0, $previous);
    }

    /**
     * The rejection `$previous` of the value under the client's key `$key`.
     */
    public static function at(string $key, ConversionException|ValueException $previous): self
    {
        return new self($key, $previous instanceof ConversionException ? $previous->reason : null, $previous);
    }
}
