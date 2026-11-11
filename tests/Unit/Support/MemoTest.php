<?php

declare(strict_types=1);

namespace Test\Unit\Support;

use Ark4ne\ApiRevisions\Support\Memo;
use RuntimeException;
use Test\TestCase;

final class MemoTest extends TestCase
{
    public function test_a_value_is_computed_once_per_key(): void
    {
        $calls = 0;
        $compute = static function () use (&$calls): mixed {
            $calls++;

            return 'value';
        };

        Memo::remember('key', $compute);
        Memo::remember('key', $compute);
        self::assertSame(1, $calls);
    }

    public function test_keys_are_memoized_apart(): void
    {
        self::assertSame(1, self::remember('one', 1));
        self::assertSame(2, self::remember('two', 2));
        self::assertSame(1, self::remember('one', 3));
    }

    public function test_a_null_value_is_memoized(): void
    {
        self::remember('key', null);

        self::assertNull(self::remember('key', 'other'));
    }

    public function test_a_computation_that_throws_stores_nothing(): void
    {
        try {
            Memo::remember('key', static fn(): never => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        self::assertSame('computed', self::remember('key', 'computed'));
    }

    public function test_flush_forgets_every_value(): void
    {
        self::remember('key', 'before');

        Memo::flush();

        self::assertSame('after', self::remember('key', 'after'));
    }

    private static function remember(string $key, mixed $value): mixed
    {
        return Memo::remember($key, static fn(): mixed => $value);
    }
}
