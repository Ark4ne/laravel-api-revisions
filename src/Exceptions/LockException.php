<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use RuntimeException;

/**
 * A lock file of `revision:lock` (model.md §9, D11, D69) that cannot be written or read. Named constructors only.
 */
final class LockException extends RuntimeException implements RevisionsException
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
        return new self("The lock file {$path} cannot be written.");
    }

    /**
     * The file holds something else than a snapshot written by `revision:lock`.
     */
    public static function invalidFile(string $path): self
    {
        $revision = basename($path, '.json');

        return new self("The lock file {$path} is invalid: restore it, or run revision:lock --revision={$revision}.");
    }
}
