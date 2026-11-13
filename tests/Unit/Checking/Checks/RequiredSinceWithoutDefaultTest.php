<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\RequiredSinceWithoutDefault;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Configuration\MissingDefault;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Closure;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class RequiredSinceWithoutDefaultTest extends TestCase
{
    use RunsChecks;

    public function test_a_required_field_added_later_without_default_is_reported(): void
    {
        self::assertSame(
            [
                ['required-since-without-default', 'error', 'priority', 'winter-25', "'priority' (request-body) is required since Winter25 without default: older clients never send it; give since(Winter25, default: ...)."],
                ['required-since-without-default', 'error', 'customer.email', 'atlas', "'customer.email' (request-body) is required since Atlas without default: older clients never send it; give since(Atlas, default: ...)."],
            ],
            self::check(new RequiredSinceWithoutDefault(), self::declaration(), [DeclarationKind::RequestBody]),
        );
    }

    public function test_a_required_field_retired_before_the_head_is_reported_once(): void
    {
        self::assertSame(
            [['required-since-without-default', 'error', 'coupon', 'winter-25', "'coupon' (request-body) is required since Winter25 without default: older clients never send it; give since(Winter25, default: ...)."]],
            self::check(
                new RequiredSinceWithoutDefault(),
                static fn(): array => ['coupon' => Schema::string()->required()->since(ApiRevision::Winter25)->until(ApiRevision::Spring26)],
                [DeclarationKind::RequestBody],
            ),
        );
    }

    public function test_a_default_an_optional_field_or_a_field_since_the_oldest_revision_reports_nothing(): void
    {
        self::assertSame([], self::check(new RequiredSinceWithoutDefault(), static fn(): array => [
            'priority' => Schema::int()->required()->since(ApiRevision::Winter25, 0),
            'note' => Schema::string()->since(ApiRevision::Winter25),
            'name' => Schema::string()->required()->since(ApiRevision::Genesis),
        ], [DeclarationKind::RequestBody]));
    }

    public function test_a_response_is_not_checked(): void
    {
        self::assertSame([], self::check(new RequiredSinceWithoutDefault(), self::declaration()));
    }

    public function test_nothing_is_reported_when_missing_defaults_are_null(): void
    {
        $compiler = new Compiler(new Configuration(missingDefault: MissingDefault::Null));

        self::assertSame([], self::check(new RequiredSinceWithoutDefault(), self::declaration(), [DeclarationKind::RequestBody], $compiler));
    }

    /**
     * @return Closure(): array<string, mixed>
     */
    private static function declaration(): Closure
    {
        return static fn(): array => [
            'priority' => Schema::int()->required()->since(ApiRevision::Winter25),
            'customer' => Schema::object([
                'email' => Schema::string()->required()->since(ApiRevision::Atlas),
            ]),
        ];
    }
}
