<?php

declare(strict_types=1);

namespace Test\Unit\Exceptions;

use Ark4ne\ApiRevisions\Exceptions\CacheException;
use Test\TestCase;

final class CacheExceptionTest extends TestCase
{
    public function test_an_unwritable_file_names_its_path(): void
    {
        self::assertSame('The compiled shapes file /var/cache/revisions.php cannot be written.', CacheException::unwritable('/var/cache/revisions.php')->getMessage());
    }

    public function test_an_invalid_file_names_its_path_and_the_fix(): void
    {
        self::assertSame('The compiled shapes file /var/cache/revisions.php is invalid: run revision:cache again.', CacheException::invalidFile('/var/cache/revisions.php')->getMessage());
    }

    public function test_an_uncompiled_shape_says_which_shapes_can_be_cached(): void
    {
        self::assertSame('Only the shapes compiled from a class can be cached.', CacheException::uncompiledShape()->getMessage());
    }
}
