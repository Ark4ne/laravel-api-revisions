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
     * @param string $type the value given when it names something (a class, a keyword), else its debug type
     */
    public static function invalidValue(string $key, string $expected, string $type): self
    {
        return new self("revisions.{$key} must be {$expected}, got {$type}.");
    }

    /**
     * A service tagged `revisions.checks` (model.md D83) that does not implement `Checking\Check`.
     */
    public static function notACheck(string $type): self
    {
        return new self("A service tagged revisions.checks must implement Ark4ne\\ApiRevisions\\Checking\\Check, got {$type}.");
    }

    /**
     * `Route::revisioned()` declared while no revision enum is configured (model.md D80): there is no revision to read.
     */
    public static function pathModeWithoutEnum(): self
    {
        return new self('Route::revisioned() reads the revision from the path: set revisions.enum to the revision enum of the API first.');
    }

    /**
     * `Route::revisioned()` declared while `revisions.resolvers` holds no `PathResolver` (the path would never be read).
     */
    public static function pathModeWithoutResolver(): self
    {
        return new self('Route::revisioned() reads the revision from the path: add Ark4ne\\ApiRevisions\\Laravel\\Http\\Resolvers\\PathResolver to revisions.resolvers.');
    }
}
