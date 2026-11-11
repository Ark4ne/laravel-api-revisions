<?php

declare(strict_types=1);

namespace Test;

use Ark4ne\ApiRevisions\Support\Memo;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base of the Unit tests: pure PHP, no Laravel application. Shared assertions and helpers live here, not in traits.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Every test starts without memoized values (`Support\Memo`).
     */
    protected function tearDown(): void
    {
        Memo::flush();

        parent::tearDown();
    }
}
