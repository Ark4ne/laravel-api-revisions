<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\DateToDateTime;
use DateTimeImmutable;
use Test\TestCase;

final class DateToDateTimeTest extends TestCase
{
    public function test_it_sets_midnight_of_the_day_in_the_timezone_of_the_value(): void
    {
        $converted = (new DateToDateTime())->convert(new DateTimeImmutable('2026-03-04 23:30:00+02:00'));

        self::assertInstanceOf(DateTimeImmutable::class, $converted);
        self::assertSame('2026-03-04T00:00:00+02:00', $converted->format(DATE_ATOM));
        self::assertSame('abc', (new DateToDateTime())->convert('abc'));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertTrue((new DateToDateTime())->lossless());
        self::assertSame([], (new DateToDateTime())->extraRules());
    }
}
