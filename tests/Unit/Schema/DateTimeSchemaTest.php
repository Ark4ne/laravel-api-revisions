<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\DateTimeSchema;
use DateTimeImmutable;
use Test\TestCase;

final class DateTimeSchemaTest extends TestCase
{
    public function test_date_time_schema_serializes_in_rfc_3339_by_default(): void
    {
        $schema = new DateTimeSchema();

        self::assertSame(Kind::DateTime, $schema->toType()->kind);
        self::assertSame('2026-04-01T10:00:00+02:00', $schema->serialize(new DateTimeImmutable('2026-04-01 10:00:00+02:00')));
    }

    public function test_date_time_schema_uses_the_configured_date_time_format(): void
    {
        $schema = (new DateTimeSchema())->configure(new Configuration(dateFormat: 'd/m/Y', dateTimeFormat: 'U'));

        self::assertSame('1775001600', $schema->serialize(new DateTimeImmutable('@1775001600')));
        self::assertSame('U', $schema->toType()->format);
    }

    public function test_format_and_configure_return_new_descriptors(): void
    {
        $schema = new DateTimeSchema();

        $schema->format('U');
        $schema->configure(new Configuration(dateTimeFormat: 'U'));

        self::assertSame('Y-m-d\TH:i:sP', $schema->toType()->format);
    }

    public function test_an_explicit_format_wins_over_the_configuration(): void
    {
        $schema = (new DateTimeSchema())->format('Y-m-d H:i')->configure(new Configuration(dateTimeFormat: 'U'));

        self::assertSame('2026-04-01 10:00', $schema->serialize(new DateTimeImmutable('2026-04-01 10:00:00')));
    }

    public function test_date_time_schema_normalizes_a_timestamp(): void
    {
        $date = (new DateTimeSchema())->normalize(1_775_001_600);

        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2026-04-01T00:00:00+00:00', $date->format(DATE_ATOM));
    }

    public function test_date_time_schema_refuses_what_is_not_a_date(): void
    {
        self::assertRefused((new DateTimeSchema())->normalize('soon-ish'), 'soon-ish', Kind::DateTime, null);
    }
}
