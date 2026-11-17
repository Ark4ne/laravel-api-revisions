<?php

declare(strict_types=1);

namespace Test\Unit\Support;

use Ark4ne\ApiRevisions\Support\Invoke;
use Test\TestCase;

final class InvokeTest extends TestCase
{
    public function test_a_closure_receives_the_value_then_the_subject(): void
    {
        $result = Invoke::value(static fn(mixed $value, mixed $subject): array => [$value, $subject], 'value', 'subject');

        self::assertSame(['value', 'subject'], $result);
    }

    public function test_a_closure_declaring_fewer_parameters_works(): void
    {
        self::assertSame('none', Invoke::value(static fn(): string => 'none', 'value', 'subject'));
        self::assertSame(1250, Invoke::value(static fn(int $cents): int => $cents, 1250, 'subject'));
    }

    public function test_a_condition_receives_the_subject_then_the_attribute(): void
    {
        $received = [];
        Invoke::condition(static function (mixed $subject, string $attribute) use (&$received): bool {
            $received = [$subject, $attribute];

            return true;
        }, ['title' => 'Book'], 'title');

        self::assertSame([['title' => 'Book'], 'title'], $received);
    }

    public function test_a_condition_passes_only_when_it_returns_true(): void
    {
        self::assertTrue(Invoke::condition(static fn(): bool => true, null, 'title'));
        self::assertFalse(Invoke::condition(static fn(): bool => false, null, 'title'));
        self::assertFalse(Invoke::condition(static fn(): int => 1, null, 'title'));
        self::assertFalse(Invoke::condition(static fn(): string => 'yes', null, 'title'));
    }
}
