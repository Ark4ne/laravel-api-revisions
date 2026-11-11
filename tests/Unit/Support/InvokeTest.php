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
}
