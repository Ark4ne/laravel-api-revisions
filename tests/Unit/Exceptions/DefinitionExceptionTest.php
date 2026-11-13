<?php

declare(strict_types=1);

namespace Test\Unit\Exceptions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Error;
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

    public function test_unknown_type_names_the_type(): void
    {
        self::assertSame(
            "Unknown type 'money': register it with TypeRegistry::register('money', …).",
            DefinitionException::unknownType('money')->getMessage(),
        );
    }

    public function test_invalid_type_name_names_the_type(): void
    {
        self::assertSame(
            "Invalid type name 'string': a custom type needs a PHP method name that Schema does not already declare.",
            DefinitionException::invalidTypeName('string')->getMessage(),
        );
    }

    public function test_invalid_custom_type_names_the_type_and_the_class(): void
    {
        self::assertSame(
            "The custom type 'money' must extend Ark4ne\\ApiRevisions\\Schema\\Descriptor, got stdClass.",
            DefinitionException::invalidCustomType('money', 'stdClass')->getMessage(),
        );
    }

    public function test_invalid_type_fragment_names_the_type(): void
    {
        self::assertSame(
            "The JSON Schema fragment of the custom type 'money' must hold scalars, arrays and nulls only.",
            DefinitionException::invalidTypeFragment('money')->getMessage(),
        );
    }

    public function test_invalid_history_names_the_revision_and_the_reason(): void
    {
        self::assertSame(
            sprintf('Invalid was(%s::Atlas): the head has no older shape.', ApiRevision::class),
            DefinitionException::invalidHistory(ApiRevision::Atlas, 'the head has no older shape')->getMessage(),
        );
    }

    public function test_source_in_history_names_the_revision(): void
    {
        self::assertSame(
            sprintf('The schema of was(%s::Genesis) and its items or children must declare no source, lifetime, history, default nor condition: older values derive from the head value through down:.', ApiRevision::class),
            DefinitionException::sourceInHistory(ApiRevision::Genesis)->getMessage(),
        );
    }

    public function test_empty_history_name_and_empty_change_name_the_revision(): void
    {
        self::assertSame(
            sprintf('Invalid was(%s::Genesis): a name must not be empty.', ApiRevision::class),
            DefinitionException::emptyHistoryName(ApiRevision::Genesis)->getMessage(),
        );
        self::assertSame(
            sprintf('Invalid was(%s::Genesis): it changes nothing (give a name, a schema, down: or up:).', ApiRevision::class),
            DefinitionException::emptyChange(ApiRevision::Genesis)->getMessage(),
        );
    }

    public function test_invalid_conversion_side_names_the_side(): void
    {
        self::assertSame(
            "Invalid conversion side 'custom': register a kind, or a custom type by the name its TypeDefinition::custom() declares.",
            DefinitionException::invalidConversionSide('custom')->getMessage(),
        );
    }

    public function test_the_compilation_errors_name_the_class(): void
    {
        self::assertSame('stdClass must implement Ark4ne\\ApiRevisions\\Schema\\Revisioned to be compiled.', DefinitionException::notRevisioned('stdClass')->getMessage());
        self::assertSame("App\\Order declares no 'request-body'.", DefinitionException::undeclaredKind('App\\Order', 'request-body')->getMessage());
        self::assertSame(
            "The declaration of App\\Order must map string keys to descriptors, got string under 'title'.",
            DefinitionException::invalidDeclaration('App\\Order', 'title', 'string')->getMessage(),
        );
        self::assertSame(
            'App\\Order read its subject (->title) while compiling: declarations must stay lazy (a key, a dotted path or a closure).',
            DefinitionException::subjectReadAtCompilation('App\\Order', '->title')->getMessage(),
        );
    }

    public function test_a_missing_descriptor_names_the_class_and_the_field(): void
    {
        self::assertSame(
            "The descriptors projected for App\\OrderResource do not match the field 'total' of its shape.",
            DefinitionException::missingDescriptor('App\\OrderResource', 'total')->getMessage(),
        );
    }

    public function test_rules_of_a_response_names_the_class(): void
    {
        self::assertSame(
            'The shape of App\\OrderResource is a response shape (down): only a request shape (up) has validation rules.',
            DefinitionException::rulesOfAResponse('App\\OrderResource')->getMessage(),
        );
    }

    public function test_declared_twice_names_the_class_the_name_and_the_revision(): void
    {
        self::assertSame(
            sprintf("App\\StoreOrderRequest declares 'status' both in its body and in its query parameters at %s::Winter25: Laravel validates them merged.", ApiRevision::class),
            DefinitionException::declaredTwice('App\\StoreOrderRequest', 'status', ApiRevision::Winter25)->getMessage(),
        );
    }

    public function test_unknown_kind_names_the_key(): void
    {
        self::assertSame("Unknown kind 'text': a rule mapper is keyed by a Kind value.", DefinitionException::unknownKind('text')->getMessage());
    }

    public function test_failed_describing_names_the_resource_and_keeps_the_error(): void
    {
        $error = new Error('boom');

        $exception = DefinitionException::failedDescribing('App\\OrderResource', $error);

        self::assertSame($error, $exception->getPrevious());
        self::assertStringStartsWith('App\\OrderResource::toArray() failed while compiling on an empty subject (boom)', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{DefinitionException, string}>
     */
    public static function checkCodes(): iterable
    {
        yield 'inverted lifetime' => [DefinitionException::invertedLifetime(ApiRevision::Atlas, ApiRevision::Genesis), 'lifetime-inverted'];
        yield 'since older than group' => [DefinitionException::sinceOlderThanGroup('a', ApiRevision::Genesis, ApiRevision::Atlas), 'since-older-than-group'];
        yield 'until newer than group' => [DefinitionException::untilNewerThanGroup('a', ApiRevision::Atlas, ApiRevision::Genesis), 'until-newer-than-group'];
        yield 'invalid history' => [DefinitionException::invalidHistory(ApiRevision::Atlas, 'the head'), 'history-unordered'];
        yield 'not revisioned' => [DefinitionException::notRevisioned('App\\X'), 'unknown-resource'];
        yield 'undeclared kind' => [DefinitionException::undeclaredKind('App\\X', 'response'), 'unknown-resource'];
        yield 'any other' => [DefinitionException::eagerSource('int'), 'definition-error'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('checkCodes')]
    public function test_a_declaration_error_carries_the_code_the_check_reports_it_under(DefinitionException $exception, string $code): void
    {
        self::assertSame($code, $exception->checkCode);
    }
}
