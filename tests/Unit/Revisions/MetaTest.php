<?php

declare(strict_types=1);

namespace Test\Unit\Revisions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Meta;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class MetaTest extends TestCase
{
    public function test_an_empty_meta_has_no_description_and_no_date(): void
    {
        $meta = new Meta();

        self::assertSame('', $meta->description);
        self::assertNull($meta->publishedAt);
        self::assertNull($meta->deprecatedAt);
        self::assertNull($meta->sunsetAt);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'not a date' => ['soon'];
        yield 'missing zero padding' => ['2026-4-1'];
        yield 'day that does not exist' => ['2026-02-30'];
        yield 'date and time' => ['2026-04-01 10:00:00'];
        yield 'other format' => ['01/04/2026'];
    }

    #[DataProvider('invalidDates')]
    public function test_an_invalid_date_in_meta_throws(string $date): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("deprecatedAt must be a Y-m-d date, got '{$date}'.");

        new Meta(deprecatedAt: $date);
    }

    public function test_every_date_argument_is_checked(): void
    {
        foreach (['publishedAt', 'deprecatedAt', 'sunsetAt'] as $argument) {
            try {
                new Meta(...[$argument => 'soon']);
                self::fail("{$argument} was not checked.");
            } catch (DefinitionException $exception) {
                self::assertStringContainsString("{$argument} must", $exception->getMessage());
            }
        }
    }

    public function test_a_revision_is_deprecated_from_its_deprecation_day_included(): void
    {
        $meta = new Meta(deprecatedAt: '2026-11-01');

        self::assertFalse($meta->isDeprecated(new DateTimeImmutable('2026-10-31 23:59:59')));
        self::assertTrue($meta->isDeprecated(new DateTimeImmutable('2026-11-01 00:00:00')));
        self::assertTrue($meta->isDeprecated(new DateTimeImmutable('2027-01-01')));
    }

    public function test_a_revision_is_sunset_from_its_sunset_day_included(): void
    {
        $meta = new Meta(sunsetAt: '2027-03-01');

        self::assertFalse($meta->isSunset(new DateTimeImmutable('2027-02-28 23:59:59')));
        self::assertTrue($meta->isSunset(new DateTimeImmutable('2027-03-01')));
    }

    public function test_without_dates_a_revision_is_never_deprecated_nor_sunset(): void
    {
        $meta = new Meta('Current');
        $now = new DateTimeImmutable('2100-01-01');

        self::assertFalse($meta->isDeprecated($now));
        self::assertFalse($meta->isSunset($now));
    }

    public function test_the_day_is_read_in_the_timezone_of_now(): void
    {
        $meta = new Meta(deprecatedAt: '2026-11-01');
        $now = new DateTimeImmutable('2026-10-31 23:30:00', new DateTimeZone('UTC'));

        self::assertFalse($meta->isDeprecated($now));
        self::assertTrue($meta->isDeprecated($now->setTimezone(new DateTimeZone('Europe/Paris'))));
    }
}
