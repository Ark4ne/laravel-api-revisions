<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base of the Unit tests: pure PHP, no Laravel application. Shared assertions and helpers live here, not in traits.
 */
abstract class TestCase extends BaseTestCase {}
