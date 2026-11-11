<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\DateSchema;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\TestCase;

final class DateSchemaTest extends TestCase
{
    public function test_date_schema_uses_the_configured_format(): void
    {
        $schema = (new DateSchema())->configure(new Configuration(dateFormat: 'd/m/Y'));

        self::assertSame('01/04/2026', $schema->serialize(new DateTimeImmutable('2026-04-01 10:00:00')));
        self::assertSame('d/m/Y', $schema->toType()->format);
    }

    public function test_without_configuration_the_format_is_the_default_one(): void
    {
        $schema = new DateSchema();

        self::assertSame(Kind::Date, $schema->toType()->kind);
        self::assertSame('Y-m-d', $schema->toType()->format);
        self::assertSame('2026-04-01', $schema->serialize(new DateTimeImmutable('2026-04-01 10:00:00')));
    }

    public function test_an_explicit_format_wins_over_the_configuration(): void
    {
        $schema = (new DateSchema())->format('Ymd')->configure(new Configuration(dateFormat: 'd/m/Y'));

        self::assertSame('20260401', $schema->serialize(new DateTimeImmutable('2026-04-01')));
        self::assertSame('Ymd', $schema->toType()->format);
    }

    public function test_format_and_configure_return_new_descriptors(): void
    {
        $schema = new DateSchema();

        $schema->format('Ymd');
        $schema->configure(new Configuration(dateFormat: 'd/m/Y'));

        self::assertSame('Y-m-d', $schema->toType()->format);
    }

    public function test_date_schema_normalizes_a_timestamp(): void
    {
        $date = (new DateSchema())->normalize(1_775_001_600);

        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2026-04-01T00:00:00+00:00', $date->format(DATE_ATOM));
    }

    public function test_date_schema_normalizes_a_date_time_to_an_immutable_one(): void
    {
        $mutable = new DateTime('2026-04-01 10:00:00+02:00');

        $date = (new DateSchema())->normalize($mutable);

        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2026-04-01T10:00:00+02:00', $date->format(DATE_ATOM));
    }

    public function test_date_schema_keeps_an_immutable_date_time(): void
    {
        $date = new DateTimeImmutable('2026-04-01');

        self::assertSame($date, (new DateSchema())->normalize($date));
    }

    public function test_date_schema_parses_a_string(): void
    {
        $date = (new DateSchema())->normalize('2026-04-01T10:00:00+02:00');

        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2026-04-01T10:00:00+02:00', $date->format(DATE_ATOM));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refused(): iterable
    {
        yield 'empty string' => [''];
        yield 'blank string' => ['  '];
        yield 'not a date' => ['not a date'];
        yield 'impossible day' => ['2026-02-30'];
        yield 'float' => [1_775_001_600.5];
        yield 'bool' => [true];
        yield 'array' => [['2026-04-01']];
        yield 'object' => [new stdClass()];
        yield 'null' => [null];
    }

    #[DataProvider('refused')]
    public function test_date_schema_refuses_other_values_without_a_cast(mixed $value): void
    {
        self::assertRefused((new DateSchema())->normalize($value), $value, Kind::Date, null);
    }

    public function test_serialize_leaves_a_value_of_another_type_as_it_is(): void
    {
        self::assertSame('raw', (new DateSchema())->serialize('raw'));
    }
}
