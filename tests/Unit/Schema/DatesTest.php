<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Schema\Dates;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class DatesTest extends TestCase
{
    public function test_an_immutable_date_time_is_kept(): void
    {
        $date = new DateTimeImmutable('2026-04-01');

        self::assertSame($date, Dates::normalize($date));
    }

    public function test_a_mutable_date_time_becomes_an_immutable_one_at_the_same_instant(): void
    {
        self::assertSame('2026-04-01T10:00:00+02:00', Dates::normalize(new DateTime('2026-04-01 10:00:00+02:00'))?->format(DATE_ATOM));
    }

    public function test_a_timestamp_is_read_in_utc(): void
    {
        self::assertSame('2026-04-01T00:00:00+00:00', Dates::normalize(1_775_001_600)?->format(DATE_ATOM));
    }

    public function test_a_string_is_parsed(): void
    {
        self::assertSame('2026-04-01T10:00:00+02:00', Dates::normalize('2026-04-01T10:00:00+02:00')?->format(DATE_ATOM));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refused(): iterable
    {
        yield 'empty string' => [''];
        yield 'blank string' => ['  '];
        yield 'blank around' => [' 2026-04-01'];
        yield 'trailing newline' => ["2026-04-01\n"];
        yield 'unparsable' => ['not a date'];
        yield 'impossible day' => ['2026-02-30'];
        yield 'impossible hour' => ['2026-04-01 25:00:00'];
        yield 'float' => [1.5];
        yield 'null' => [null];
    }

    #[DataProvider('refused')]
    public function test_other_values_are_refused(mixed $value): void
    {
        self::assertNull(Dates::normalize($value));
    }
}
