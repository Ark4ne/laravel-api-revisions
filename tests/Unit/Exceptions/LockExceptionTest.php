<?php

declare(strict_types=1);

namespace Test\Unit\Exceptions;

use Ark4ne\ApiRevisions\Exceptions\LockException;
use Test\TestCase;

final class LockExceptionTest extends TestCase
{
    public function test_an_unwritable_file_names_its_path(): void
    {
        self::assertSame('The lock file /app/revisions.lock/genesis.json cannot be written.', LockException::unwritable('/app/revisions.lock/genesis.json')->getMessage());
    }

    public function test_an_invalid_file_names_its_path_and_the_fix(): void
    {
        self::assertSame(
            'The lock file /app/revisions.lock/genesis.json is invalid: restore it, or run revision:lock --revision=genesis.',
            LockException::invalidFile('/app/revisions.lock/genesis.json')->getMessage(),
        );
    }
}
