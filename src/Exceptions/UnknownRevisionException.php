<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use RuntimeException;

/**
 * A revision asked by a client (a header, a query parameter, a path segment) that the API does not know (model.md
 * §8): rendered **400** by the Laravel layer (US 8.3), with the accepted values. Named constructors only.
 */
final class UnknownRevisionException extends RuntimeException implements RevisionsException
{
    /**
     * @param list<string> $accepted
     */
    private function __construct(
        string $message,
        public readonly string $value,
        public readonly array $accepted,
    ) {
        parent::__construct($message);
    }

    /**
     * `$value` matches no revision.
     *
     * @param string $value the value sent, or the debug type of a value that is not a string (`array`)
     * @param list<string> $accepted the backed values of the revisions, oldest first
     */
    public static function unknown(string $value, array $accepted): self
    {
        return new self(sprintf("Unknown API revision '%s', expected one of: %s.", $value, implode(', ', $accepted)), $value, $accepted);
    }
}
