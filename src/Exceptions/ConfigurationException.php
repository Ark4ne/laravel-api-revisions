<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use InvalidArgumentException;

/**
 * An invalid `config/revisions.php` (model.md §8). Named constructors only.
 */
final class ConfigurationException extends InvalidArgumentException implements RevisionsException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * `revisions.enum` missing, or not a backed enum implementing `Revisions\Revision`.
     *
     * @param string $type the class given, or the debug type of the value
     */
    public static function notARevisionEnum(string $type): self
    {
        return new self("revisions.enum must be a backed enum implementing Ark4ne\\ApiRevisions\\Revisions\\Revision, got {$type}.");
    }

    /**
     * A key of `config/revisions.php` holding what it cannot hold.
     *
     * @param string $key the dotted key, e.g. `discovery.paths`
     * @param string $expected what it holds, e.g. "a non-empty string"
     * @param string $type the debug type of the value given
     */
    public static function invalidValue(string $key, string $expected, string $type): self
    {
        return new self("revisions.{$key} must be {$expected}, got {$type}.");
    }
}
