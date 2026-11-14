<?php

declare(strict_types=1);

namespace Test\Unit\Export;

use Ark4ne\ApiRevisions\Export\DatePattern;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class DatePatternTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function formats(): iterable
    {
        yield 'day first' => ['d/m/Y', '^\d{2}/\d{2}/\d{4}$'];
        yield 'short tokens' => ['j.n.y', '^\d{1,2}\.\d{1,2}\.\d{2}$'];
        yield 'time with offset' => ['Y-m-d H:i:sO', '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{4}$'];
        yield 'escaped literal and fractions' => ['Y-m-d\TG:i:s.vP', '^\d{4}-\d{2}-\d{2}T\d{1,2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$'];
        yield 'microseconds' => ['His.u', '^\d{2}\d{2}\d{2}\.\d{6}$'];
        yield 'a day name' => ['D, d M Y', null];
        yield 'a timestamp' => ['U', null];
        yield 'a trailing backslash' => ['Y\\', null];
    }

    #[DataProvider('formats')]
    public function test_a_numeric_date_format_gives_its_pattern(string $format, ?string $pattern): void
    {
        self::assertSame($pattern, DatePattern::of($format));
    }

    public function test_the_pattern_matches_the_dates_of_its_format(): void
    {
        $pattern = DatePattern::of('d/m/Y H:i');
        self::assertNotNull($pattern);

        self::assertSame(1, preg_match("~{$pattern}~", (new DateTimeImmutable('2026-02-03 04:05'))->format('d/m/Y H:i')));
        self::assertSame(0, preg_match("~{$pattern}~", '2026-02-03 04:05'));
    }
}
