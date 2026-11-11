<?php

declare(strict_types=1);

namespace Test\Unit\Conversion;

use Ark4ne\ApiRevisions\Conversion\ClosureConverter;
use Ark4ne\ApiRevisions\Conversion\ExplicitConverter;
use Test\TestCase;

final class ClosureConverterTest extends TestCase
{
    public function test_a_closure_converter_is_explicit(): void
    {
        $converter = new ClosureConverter(static fn(int $cents): string => (string) $cents);

        self::assertContains(ExplicitConverter::class, class_implements($converter));
        self::assertFalse($converter->lossless());
        self::assertSame([], $converter->extraRules());
    }

    public function test_it_calls_the_closure_with_the_value_and_the_subject(): void
    {
        $converter = new ClosureConverter(static fn(int $cents, string $currency): string => $currency . ' ' . $cents / 100);

        self::assertSame('EUR 12.5', $converter->convert(1250, 'EUR'));
    }

    public function test_the_subject_defaults_to_null(): void
    {
        $converter = new ClosureConverter(static fn(mixed $value, mixed $subject): array => [$value, $subject]);

        self::assertSame([3, null], $converter->convert(3));
    }

    public function test_it_keeps_its_closure(): void
    {
        $closure = static fn(string $value): string => $value;

        self::assertSame($closure, (new ClosureConverter($closure))->closure);
    }
}
