<?php

declare(strict_types=1);

namespace Test\Unit\Exceptions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\IntRevision;
use Test\TestCase;

final class DefinitionExceptionTest extends TestCase
{
    public function test_mixed_revisions_names_both_cases_and_their_enums(): void
    {
        $exception = DefinitionException::mixedRevisions(ApiRevision::Atlas, IntRevision::First);

        self::assertSame(
            'Revisions of two enums cannot be mixed: Test\Fixtures\ApiRevision::Atlas and Test\Fixtures\Revisions\IntRevision::First.',
            $exception->getMessage(),
        );
    }

    public function test_invalid_order_names_the_enum_and_the_reason(): void
    {
        $exception = DefinitionException::invalidOrder(ApiRevision::class, 'Atlas is missing');

        self::assertSame(
            'Test\Fixtures\ApiRevision::order() must list every case exactly once: Atlas is missing.',
            $exception->getMessage(),
        );
    }

    public function test_invalid_meta_date_names_the_argument_and_the_value(): void
    {
        self::assertSame(
            "sunsetAt must be a Y-m-d date, got 'soon'.",
            DefinitionException::invalidMetaDate('sunsetAt', 'soon')->getMessage(),
        );
    }

    public function test_invalid_meta_names_the_case_and_keeps_the_reason(): void
    {
        $reason = DefinitionException::invalidMetaDate('sunsetAt', 'soon');

        $exception = DefinitionException::invalidMeta(ApiRevision::Atlas, $reason);

        self::assertSame("Invalid #[Meta] on Test\Fixtures\ApiRevision::Atlas: sunsetAt must be a Y-m-d date, got 'soon'.", $exception->getMessage());
        self::assertSame($reason, $exception->getPrevious());
    }

    public function test_foreign_revision_names_the_case_and_the_required_enum(): void
    {
        self::assertSame(
            'Test\Fixtures\Revisions\IntRevision::First is not a revision of Test\Fixtures\ApiRevision.',
            DefinitionException::foreignRevision(IntRevision::First, ApiRevision::class)->getMessage(),
        );
    }

    public function test_eager_source_names_the_type_given(): void
    {
        self::assertSame(
            'A source is a key, a dotted path or a closure, never an evaluated value: got int.',
            DefinitionException::eagerSource('int')->getMessage(),
        );
    }

    public function test_invalid_source_key_names_the_key(): void
    {
        self::assertSame(
            "Invalid source key 'a..b': a key or each segment of a dotted path must not be empty.",
            DefinitionException::invalidSourceKey('a..b')->getMessage(),
        );
    }

    public function test_not_backed_enum_names_the_class(): void
    {
        self::assertSame('Schema::enum() needs a backed enum, got stdClass.', DefinitionException::notBackedEnum('stdClass')->getMessage());
    }

    public function test_invalid_child_names_the_key_and_the_type(): void
    {
        self::assertSame(
            "Invalid child 'street' of Schema::object(): a descriptor is expected, got string.",
            DefinitionException::invalidChild('street', 'string')->getMessage(),
        );
    }

    public function test_invalid_child_key_names_the_key(): void
    {
        self::assertSame(
            'Invalid child key 0 of Schema::object(): children are keyed by non-empty strings.',
            DefinitionException::invalidChildKey(0)->getMessage(),
        );
        self::assertSame(
            "Invalid child key '' of Schema::object(): children are keyed by non-empty strings.",
            DefinitionException::invalidChildKey('')->getMessage(),
        );
    }

    public function test_inverted_lifetime_names_both_cases_and_their_enums(): void
    {
        self::assertSame(
            sprintf('A field cannot exist from %1$s::Atlas until %1$s::Genesis: its since is newer than its until.', ApiRevision::class),
            DefinitionException::invertedLifetime(ApiRevision::Atlas, ApiRevision::Genesis)->getMessage(),
        );
    }

    public function test_invalid_presence_key_names_the_key(): void
    {
        self::assertSame(
            "Invalid presence key 'a..b': a key or each segment of a dotted path must not be empty.",
            DefinitionException::invalidPresenceKey('a..b')->getMessage(),
        );
    }

    public function test_invalid_json_schema_names_the_rule(): void
    {
        self::assertSame(
            'The JSON Schema of the rule App\\Rules\\Postcode must be keywords holding scalars, arrays and nulls only.',
            DefinitionException::invalidJsonSchema('App\\Rules\\Postcode')->getMessage(),
        );
    }

    public function test_since_older_than_group_names_the_field_and_both_cases(): void
    {
        self::assertSame(
            sprintf("The field 'notes' cannot exist since %1\$s::Genesis in a group since %1\$s::Atlas: a member's since must be at or after its group's.", ApiRevision::class),
            DefinitionException::sinceOlderThanGroup('notes', ApiRevision::Genesis, ApiRevision::Atlas)->getMessage(),
        );
    }

    public function test_until_newer_than_group_names_the_field_and_both_cases(): void
    {
        self::assertSame(
            sprintf("The field 'name' cannot exist until %1\$s::Atlas in a group until %1\$s::Genesis: a member's until must be at or before its group's.", ApiRevision::class),
            DefinitionException::untilNewerThanGroup('name', ApiRevision::Atlas, ApiRevision::Genesis)->getMessage(),
        );
    }

    public function test_invalid_group_member_names_the_key_and_the_type(): void
    {
        self::assertSame(
            "Invalid member 'total' of a group: a descriptor is expected, got int.",
            DefinitionException::invalidGroupMember('total', 'int')->getMessage(),
        );
    }

    public function test_invalid_group_key_names_the_key(): void
    {
        self::assertSame(
            'Invalid member key 3 of a group: members are keyed by non-empty strings.',
            DefinitionException::invalidGroupKey(3)->getMessage(),
        );
        self::assertSame(
            "Invalid member key '' of a group: members are keyed by non-empty strings.",
            DefinitionException::invalidGroupKey('')->getMessage(),
        );
    }
}
