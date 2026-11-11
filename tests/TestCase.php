<?php

declare(strict_types=1);

namespace Test;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use Ark4ne\ApiRevisions\Schema\InvalidValue;
use Ark4ne\ApiRevisions\Support\Memo;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base of the Unit tests: pure PHP, no Laravel application. Shared assertions and helpers live here, not in traits.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Every test starts without memoized values (`Support\Memo`) and with a new process-local revision scope.
     */
    protected function tearDown(): void
    {
        Memo::flush();
        RevisionScope::provider(null);

        parent::tearDown();
    }

    /**
     * Asserts a normalization refused `$value`, strictly: the value, the kind and the lenient cast.
     */
    protected static function assertRefused(mixed $refusal, mixed $value, Kind $expected, mixed $cast): void
    {
        self::assertInstanceOf(InvalidValue::class, $refusal);
        self::assertSame($value, $refusal->value);
        self::assertSame($expected, $refusal->expected);
        self::assertSame($cast, $refusal->cast);
    }
}
