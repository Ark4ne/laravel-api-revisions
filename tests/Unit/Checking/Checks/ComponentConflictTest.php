<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\ComponentConflict;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use Test\Fixtures\Address;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\RelationSchema;
use Test\TestCase;

final class ComponentConflictTest extends TestCase
{
    use RunsChecks;

    public function test_two_bodies_under_one_component_name_are_reported_once_at_the_newest_revision(): void
    {
        self::assertSame(
            [
                ['component-conflict', 'error', 'billing.address', 'atlas', "The component 'Address' of 'billing.address' (response) at atlas differs from another use of it: give each shape its own name."],
                // the first conflict of a document stops its export: 'items.*' shows once 'billing' is fixed
                ['component-conflict', 'error', 'billing.address', 'atlas', "The component 'Address' of 'billing.address' (request-body) at atlas differs from another use of it: give each shape its own name."],
            ],
            self::check(new ComponentConflict(), static fn(): array => [
                'to' => Address::schema(),
                'billing' => Schema::object(['address' => Schema::object(['line' => Schema::string()])->component('Address')]),
                'items' => Schema::list(Schema::object(['line' => Schema::string()])->component('Address')),
            ], [DeclarationKind::Response, DeclarationKind::RequestBody]),
        );
    }

    public function test_a_conflict_only_under_a_was_is_reported_at_its_revision(): void
    {
        self::assertSame(
            [['component-conflict', 'error', 'from', 'winter-25', "The component 'Address' of 'from' (response) at winter-25 differs from another use of it: give each shape its own name."]],
            self::check(new ComponentConflict(), static fn(): array => [
                'to' => Address::schema(),
                'from' => Address::schema()->was(ApiRevision::Winter25, schema: Schema::object(['street' => Schema::string()])->component('Address')),
            ]),
        );
    }

    public function test_one_component_used_everywhere_reports_nothing(): void
    {
        self::assertSame([], self::check(new ComponentConflict(), static fn(): array => [
            'to' => Address::schema(),
            'from' => Address::schema()->nullable(),
            'stops' => Schema::list(Address::schema()),
        ]));
    }

    public function test_two_fragments_of_a_custom_type_declaring_its_identity_report_nothing(): void
    {
        TypeRegistry::register('relation', RelationSchema::class);

        self::assertSame([], self::check(new ComponentConflict(), static fn(): array => [
            'customer' => new RelationSchema('customers'),
            'product' => new RelationSchema('products'),
        ]));
    }

    public function test_a_conflict_in_the_items_of_a_list_is_reported_on_them(): void
    {
        self::assertSame(
            [['component-conflict', 'error', 'items.*', 'atlas', "The component 'Address' of 'items.*' (response) at atlas differs from another use of it: give each shape its own name."]],
            self::check(new ComponentConflict(), static fn(): array => [
                'to' => Address::schema(),
                'items' => Schema::list(Schema::object(['line' => Schema::string()])->component('Address')),
            ]),
        );
    }
}
