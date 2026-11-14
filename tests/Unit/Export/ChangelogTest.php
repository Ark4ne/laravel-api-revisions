<?php

declare(strict_types=1);

namespace Test\Unit\Export;

use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Export\Change;
use Ark4ne\ApiRevisions\Export\ChangeKind;
use Ark4ne\ApiRevisions\Export\Changelog;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Closure;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Declarations\PodcastDeclaration;
use Test\TestCase;

final class ChangelogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CheckedDeclaration::reset();
    }

    protected function tearDown(): void
    {
        CheckedDeclaration::reset();

        parent::tearDown();
    }

    public function test_the_changelog_lists_a_rename_as_a_rename(): void
    {
        $changelog = new Changelog(new Shapes());

        self::assertEquals(
            [
                new Change(ChangeKind::Renamed, 'status', 'state'),
                new Change(ChangeKind::TypeChanged, 'total', before: ['type' => 'string'], after: ['type' => 'integer']),
                new Change(ChangeKind::TypeChanged, 'placed_at', before: ['type' => 'string', 'format' => 'date'], after: ['type' => 'string', 'format' => 'date-time']),
            ],
            $changelog->changes(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Spring26),
        );
        self::assertEquals(
            [new Change(ChangeKind::Renamed, 'name', 'full_name'), new Change(ChangeKind::Added, 'orders')],
            $changelog->changes(CustomerDeclaration::class, DeclarationKind::Response, ApiRevision::Spring26),
        );
    }

    public function test_a_class_is_added_in_its_first_revision_and_removed_after_its_last(): void
    {
        $changelog = new Changelog(new Shapes());
        CheckedDeclaration::$describe = static fn(): array => ['title' => Schema::string()];
        CheckedDeclaration::$lifetime = Lifetime::until(ApiRevision::Winter25);

        try {
            self::assertEquals([new Change(ChangeKind::Added, '')], $changelog->changes(PodcastDeclaration::class, DeclarationKind::Response, ApiRevision::Spring26));
            self::assertEquals([new Change(ChangeKind::Renamed, 'minutes', 'duration')], $changelog->changes(PodcastDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas));
            self::assertSame([], $changelog->changes(PodcastDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25));
            self::assertEquals([new Change(ChangeKind::Removed, '')], $changelog->changes(CheckedDeclaration::class, DeclarationKind::Response, ApiRevision::Spring26));
            self::assertSame([], $changelog->changes(CheckedDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas));
            self::assertStringContainsString("### Test\\Fixtures\\Declarations\\PodcastDeclaration (response)\n\n- The class is added.", $changelog->markdown([PodcastDeclaration::class], ApiRevision::Genesis, ApiRevision::Atlas));
            self::assertStringContainsString("- The class is removed.", $changelog->markdown([CheckedDeclaration::class], ApiRevision::Genesis, ApiRevision::Atlas));
        } finally {
            CheckedDeclaration::reset();
        }
    }

    public function test_the_oldest_revision_changes_nothing(): void
    {
        self::assertSame([], (new Changelog())->changes(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis));
    }

    public function test_added_and_removed_fields_follow_the_declaration_the_removed_last(): void
    {
        self::assertEquals(
            [new Change(ChangeKind::Added, 'tags'), new Change(ChangeKind::Added, 'notes'), new Change(ChangeKind::Removed, 'name')],
            (new Changelog())->changes(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas),
        );
    }

    public function test_object_children_and_list_items_are_walked_by_their_path(): void
    {
        self::assertEquals(
            [
                new Change(ChangeKind::Renamed, 'billing.zip_code', 'billing.zip'),
                new Change(ChangeKind::Added, 'billing.city'),
                new Change(ChangeKind::Removed, 'billing.country'),
                new Change(ChangeKind::TypeChanged, 'lines.*.qty', before: ['type' => 'integer'], after: ['type' => 'integer', 'minimum' => 1]),
                new Change(ChangeKind::Added, 'lines.*.sku'),
            ],
            self::changes(static fn(): array => [
                'billing' => Schema::object([
                    'zip_code' => Schema::string()->was(ApiRevision::Spring26, name: 'zip'),
                    'city' => Schema::string()->since(ApiRevision::Atlas),
                    'country' => Schema::string()->until(ApiRevision::Spring26),
                ]),
                'lines' => Schema::list(Schema::object([
                    'sku' => Schema::string()->since(ApiRevision::Atlas),
                    'qty' => Schema::int()->min(1)->was(ApiRevision::Spring26, schema: Schema::int()),
                ])),
            ], ApiRevision::Atlas),
        );
    }

    public function test_a_composite_under_a_was_schema_on_one_side_is_compared_by_its_whole_type(): void
    {
        self::assertEquals(
            [new Change(ChangeKind::TypeChanged, 'tags', before: ['type' => 'array', 'items' => ['type' => 'string']], after: ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']])],
            self::changes(static fn(): array => [
                'tags' => Schema::list(Schema::string())->min(1)->was(ApiRevision::Spring26, schema: Schema::list(Schema::string())),
            ], ApiRevision::Atlas),
        );
    }

    public function test_the_documentation_of_children_and_items_never_changes_a_type(): void
    {
        self::assertEquals(
            [new Change(ChangeKind::TypeChanged, 'tags', before: ['type' => 'array', 'items' => ['type' => 'string']], after: ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']])],
            self::changes(static fn(): array => [
                'tags' => Schema::list(Schema::string()->describe('A tag')->example('red'))->min(1)->was(ApiRevision::Spring26, schema: Schema::list(Schema::string())),
                'box' => Schema::object(['size' => Schema::int()])
                    ->was(ApiRevision::Spring26, schema: Schema::object(['size' => Schema::int()->describe('In cm')->deprecated()]))
                    ->was(ApiRevision::Winter25, schema: Schema::object(['size' => Schema::int()])),
            ], ApiRevision::Atlas),
        );
        self::assertSame([], self::changes(static fn(): array => [
            'box' => Schema::object(['size' => Schema::int()])
                ->was(ApiRevision::Spring26, schema: Schema::object(['size' => Schema::int()->describe('In cm')->deprecated()]))
                ->was(ApiRevision::Winter25, schema: Schema::object(['size' => Schema::int()])),
        ], ApiRevision::Spring26));
    }

    public function test_nullability_and_constraints_change_the_type_but_documentation_does_not(): void
    {
        self::assertEquals(
            [new Change(ChangeKind::TypeChanged, 'code', before: ['type' => 'string'], after: ['type' => ['string', 'null'], 'maxLength' => 3])],
            self::changes(static fn(): array => [
                'code' => Schema::string()->max(3)->nullable()->was(ApiRevision::Spring26, schema: Schema::string()),
                'label' => Schema::string()->describe('Now documented')->deprecated(),
            ], ApiRevision::Atlas),
        );
    }

    public function test_the_changelog_uses_the_revision_descriptions(): void
    {
        $markdown = (new Changelog())->markdown([OrderDeclaration::class, CheckedDeclaration::class], ApiRevision::Genesis, ApiRevision::Atlas);

        self::assertSame(<<<'MD'
            # Changelog

            ## atlas

            Tags and notes; `name` replaced by `first_name` / `last_name`

            ### Test\Fixtures\Declarations\OrderDeclaration (response)

            - Added `tags`.
            - Added `notes`.
            - Removed `name`.

            ## spring-26

            Totals in cents, `state` renamed `status`

            Published 2026-04-01 · deprecated 2026-11-01 · sunset 2027-05-01

            ### Test\Fixtures\Declarations\OrderDeclaration (response)

            - Renamed `state` to `status`.
            - Changed the type of `total`: `{"type":"string"}` → `{"type":"integer"}`.
            - Changed the type of `placed_at`: `{"type":"string","format":"date"}` → `{"type":"string","format":"date-time"}`.

            ## winter-25

            Totals become decimal strings

            Published 2025-12-01

            ### Test\Fixtures\Declarations\OrderDeclaration (response)

            - Changed the type of `total`: `{"type":"number"}` → `{"type":"string"}`.

            MD, $markdown);
    }

    public function test_a_revision_without_change_says_so_and_the_base_is_excluded(): void
    {
        CheckedDeclaration::$describe = static fn(): array => ['title' => Schema::string()];

        self::assertSame(<<<'MD'
            # Changelog

            ## spring-26

            Totals in cents, `state` renamed `status`

            Published 2026-04-01 · deprecated 2026-11-01 · sunset 2027-05-01

            No change.

            MD, (new Changelog())->markdown([CheckedDeclaration::class], ApiRevision::Winter25, ApiRevision::Spring26));
    }

    /**
     * @param Closure(): array<string, mixed> $describe
     *
     * @return list<Change>
     */
    private static function changes(Closure $describe, ApiRevision $revision): array
    {
        CheckedDeclaration::$describe = $describe;

        return (new Changelog(new Shapes()))->changes(CheckedDeclaration::class, DeclarationKind::Response, $revision);
    }
}
