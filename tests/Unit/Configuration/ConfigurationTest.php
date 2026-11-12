<?php

declare(strict_types=1);

namespace Test\Unit\Configuration;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Configuration\MissingDefault;
use Test\TestCase;

final class ConfigurationTest extends TestCase
{
    public function test_the_default_date_formats_are_iso_day_and_rfc_3339(): void
    {
        $configuration = new Configuration();

        self::assertSame('Y-m-d', $configuration->dateFormat);
        self::assertSame('Y-m-d\TH:i:sP', $configuration->dateTimeFormat);
    }

    public function test_a_missing_default_is_an_error_by_default_and_can_be_null(): void
    {
        self::assertSame(MissingDefault::Error, (new Configuration())->missingDefault);
        self::assertSame(MissingDefault::Null, (new Configuration(missingDefault: MissingDefault::Null))->missingDefault);
    }
}
