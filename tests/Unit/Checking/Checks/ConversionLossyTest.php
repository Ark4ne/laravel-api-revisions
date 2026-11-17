<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\ConversionLossy;
use Ark4ne\ApiRevisions\Conversion\Cast;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class ConversionLossyTest extends TestCase
{
    use RunsChecks;

    public function test_a_lossy_conversion_without_cast_is_reported(): void
    {
        self::assertSame(
            [
                ['conversion-lossy', 'warning', 'total', 'winter-25', "The automatic down conversion from string to int of 'total' (response) at was(Winter25) may lose information: declare down: to decide."],
                ['conversion-lossy', 'warning', 'lines.*', 'genesis', "The automatic down conversion from float to int of 'lines.*' (response) at was(Genesis) may lose information: declare down: to decide."],
            ],
            self::check(new ConversionLossy(), static fn(): array => [
                'total' => Schema::string()->was(ApiRevision::Winter25, schema: Schema::int()),
                'lines' => Schema::list(Schema::float()->was(ApiRevision::Genesis, schema: Schema::int())),
            ]),
        );
    }

    public function test_a_lossy_conversion_is_checked_in_the_direction_of_the_declaration_kind(): void
    {
        self::assertSame(
            [['conversion-lossy', 'warning', 'total', 'winter-25', "The automatic up conversion from string to int of 'total' (request-body) at was(Winter25) may lose information: declare up: to decide."]],
            self::check(
                new ConversionLossy(),
                static fn(): array => ['total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string())],
                [DeclarationKind::RequestBody],
            ),
        );
    }

    public function test_a_lossless_or_declared_conversion_reports_nothing(): void
    {
        self::assertSame([], self::check(new ConversionLossy(), static fn(): array => [
            'total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string()),
            'amount' => Schema::float()->was(ApiRevision::Winter25, schema: Schema::int(), down: Cast::round()),
            'born_at' => Schema::dateTime()->was(ApiRevision::Winter25, schema: Schema::date()),
            'paid_on' => Schema::date()->was(ApiRevision::Winter25, schema: Schema::dateTime()),
        ]));
    }

    public function test_a_date_time_sent_where_the_head_is_a_date_warns_its_time_is_dropped(): void
    {
        self::assertSame(
            [['conversion-lossy', 'warning', 'paid_on', 'winter-25', "The automatic up conversion from date-time to date of 'paid_on' (request-body) at was(Winter25) may lose information: declare up: to decide."]],
            self::check(
                new ConversionLossy(),
                static fn(): array => [
                    'paid_on' => Schema::date()->was(ApiRevision::Winter25, schema: Schema::dateTime()),
                    'born_at' => Schema::dateTime()->was(ApiRevision::Winter25, schema: Schema::date()),
                ],
                [DeclarationKind::RequestBody],
            ),
        );
    }
}
