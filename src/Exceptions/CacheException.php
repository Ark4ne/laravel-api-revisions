<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use RuntimeException;

/**
 * The compiled shapes file of `revision:cache` (model.md §10) that cannot be written or read. Named constructors only.
 */
final class CacheException extends RuntimeException implements RevisionsException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * The file, or its directory, cannot be written.
     */
    public static function unwritable(string $path): self
    {
        return new self("The compiled shapes file {$path} cannot be written.");
    }

    /**
     * The file holds something else than the shapes written by `revision:cache`.
     */
    public static function invalidFile(string $path): self
    {
        return new self("The compiled shapes file {$path} is invalid: run revision:cache again.");
    }

    /**
     * A shape cached without the declaration it was compiled from (built by hand, not by the `Compiler`).
     */
    public static function uncompiledShape(): self
    {
        return new self('Only the shapes compiled from a class can be cached.');
    }
}
