<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\ConversionMissing;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\RelationSchema;
use Test\TestCase;

final class ConversionMissingTest extends TestCase
{
    use RunsChecks;

    public function test_a_conversion_neither_automatic_nor_declared_is_reported_once(): void
    {
        self::assertSame(
            [
                ['conversion-missing', 'error', 'tags', 'winter-25', "No down conversion from string to list for 'tags' (response) at was(Winter25): declare down:."],
                ['conversion-missing', 'error', 'customer.code', 'spring-26', "No down conversion from string to object for 'customer.code' (response) at was(Spring26): declare down:."],
            ],
            self::check(new ConversionMissing(), static fn(): array => [
                'tags' => Schema::string()->was(ApiRevision::Winter25, schema: Schema::list(Schema::string())),
                'customer' => Schema::object([
                    'code' => Schema::string()->was(ApiRevision::Spring26, schema: Schema::object([])),
                ]),
            ]),
        );
    }

    public function test_only_the_direction_of_the_declaration_kind_is_checked(): void
    {
        self::assertSame(
            [['conversion-missing', 'error', 'tags', 'winter-25', "No up conversion from list to string for 'tags' (request-body) at was(Winter25): declare up:."]],
            self::check(
                new ConversionMissing(),
                static fn(): array => ['tags' => Schema::string()->was(ApiRevision::Winter25, schema: Schema::list(Schema::string()), down: static fn(): array => [])],
                [DeclarationKind::RequestBody],
            ),
        );
    }

    public function test_another_fragment_of_a_custom_type_declaring_its_identity_reports_nothing(): void
    {
        TypeRegistry::register('relation', RelationSchema::class);

        self::assertSame([], self::check(new ConversionMissing(), static fn(): array => [
            'buyer' => (new RelationSchema('customers'))->was(ApiRevision::Winter25, schema: new RelationSchema('buyers')),
        ], [DeclarationKind::Response, DeclarationKind::RequestBody]));
    }

    public function test_an_automatic_or_declared_conversion_reports_nothing(): void
    {
        self::assertSame([], self::check(new ConversionMissing(), static fn(): array => [
            'total' => Schema::string()->was(ApiRevision::Winter25, schema: Schema::int()),
            'born_at' => Schema::dateTime()->was(ApiRevision::Winter25, schema: Schema::date()),
            'paid_on' => Schema::date()->was(ApiRevision::Winter25, schema: Schema::dateTime()),
            'tags' => Schema::string()->was(ApiRevision::Winter25, schema: Schema::list(Schema::string()), down: static fn(string $tags): array => explode(',', $tags)),
        ]));
    }
}
