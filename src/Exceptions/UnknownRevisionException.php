<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use RuntimeException;

/**
 * A revision asked by a client (a header, a query parameter, a path segment) that the API does not know: rendered **400** by the Laravel layer, with the accepted values. Named constructors only.
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
     * `$value` matches no revision; a value longer than 64 characters is cut (a client controls it, it is echoed in
     * the 400 body).
     *
     * @param string $value the value sent, or the debug type of a value that is not a string (`array`)
     * @param list<string> $accepted the backed values of the revisions, oldest first
     */
    public static function unknown(string $value, array $accepted): self
    {
        if (mb_strlen($value) > 64) {
            $value = mb_substr($value, 0, 63) . '…';
        }

        return new self(sprintf("Unknown API revision '%s', expected one of: %s.", $value, implode(', ', $accepted)), $value, $accepted);
    }
}
