<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\DateTimeToDate;
use DateTimeImmutable;
use Test\TestCase;

final class DateTimeToDateTest extends TestCase
{
    public function test_it_keeps_the_instant_and_its_timezone(): void
    {
        $at = new DateTimeImmutable('2026-03-04 23:30:00+02:00');

        self::assertSame($at, (new DateTimeToDate())->convert($at));
        self::assertSame('abc', (new DateTimeToDate())->convert('abc'));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertTrue((new DateTimeToDate())->lossless());
        self::assertSame([], (new DateTimeToDate())->extraRules());
    }
}
