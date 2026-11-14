<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\SchemaOverride;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Rules\PostcodeRule;
use Test\TestCase;

final class SchemaOverrideTest extends TestCase
{
    use RunsChecks;

    public function test_a_rule_keyword_overriding_the_type_is_reported(): void
    {
        self::assertSame(
            [
                ['schema-override', 'warning', 'zip', 'atlas', "The rule keyword 'type' of 'zip' (response) overrides \"string\" with \"integer\"."],
                ['schema-override', 'warning', 'lines.*', 'atlas', "The rule keyword 'maximum' of 'lines.*' (response) overrides 5 with 9."],
                ['schema-override', 'warning', 'code', 'winter-25', "The rule keyword 'minLength' of 'code' (response) overrides 2 with 3."],
            ],
            self::check(new SchemaOverride(), static fn(): array => [
                'zip' => Schema::string()->rules(new PostcodeRule(['type' => 'integer', 'pattern' => '^\d{5}$'])),
                'lines' => Schema::list(Schema::int()->max(5)->rules(new PostcodeRule(['maximum' => 9]))),
                'code' => Schema::string()->was(ApiRevision::Winter25, schema: Schema::string()->min(2)->rules(new PostcodeRule(['minLength' => 3]))),
            ]),
        );
    }

    public function test_a_rule_keyword_dropping_null_from_a_nullable_field_is_reported(): void
    {
        self::assertSame(
            [['schema-override', 'warning', 'zip', 'atlas', "The rule keyword 'type' of 'zip' (response) overrides [\"string\",\"null\"] with \"string\"."]],
            self::check(new SchemaOverride(), static fn(): array => [
                'zip' => Schema::string()->nullable()->rules(new PostcodeRule(['type' => 'string'])),
            ]),
        );
    }

    public function test_a_rule_keyword_adding_to_the_type_or_repeating_it_reports_nothing(): void
    {
        self::assertSame([], self::check(new SchemaOverride(), static fn(): array => [
            'zip' => Schema::string()->rules(new PostcodeRule(['type' => 'string', 'pattern' => '^\d{5}$'])),
            'max' => Schema::float()->max(5)->rules(new PostcodeRule(['maximum' => 5.0])),
        ]));
    }
}
