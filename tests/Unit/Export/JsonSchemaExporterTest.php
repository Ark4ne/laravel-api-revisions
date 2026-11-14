<?php

declare(strict_types=1);

namespace Test\Unit\Export;

use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\ObjectSchema;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use Closure;
use DateTimeInterface;
use Test\Fixtures\Address;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Declarations\ShippingDeclaration;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Enums\Priority;
use Test\Fixtures\MoneySchema;
use Test\Fixtures\RelationSchema;
use Test\Fixtures\Rules\PostcodeRule;
use Test\TestCase;

final class JsonSchemaExporterTest extends TestCase
{
    private const string CUSTOMER = '#/$defs/Test.Fixtures.Declarations.CustomerDeclaration';

    protected function setUp(): void
    {
        parent::setUp();

        CheckedDeclaration::reset();
    }

    public function test_each_revision_exports_its_own_schema(): void
    {
        $describe = static fn(): array => [
            'title' => Schema::string()->min(1)->max(80)->describe('The title')->example('Lamp'),
            'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
            'total' => Schema::int()->min(0)->was(ApiRevision::Winter25, schema: Schema::string()),
            'notes' => Schema::string()->nullable()->since(ApiRevision::Atlas),
            'label' => Schema::string()->when(static fn(): bool => true),
        ];

        self::assertEquals([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => CheckedDeclaration::class,
            '$comment' => 'response at atlas: Tags and notes; `name` replaced by `first_name` / `last_name`',
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80, 'description' => 'The title', 'examples' => ['Lamp']],
                'status' => ['type' => 'string', 'enum' => ['pending', 'paid', 'shipped']],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'notes' => ['type' => ['string', 'null']],
                'label' => ['type' => 'string'],
            ],
            'required' => ['title', 'status', 'total', 'notes'],
        ], self::export($describe, ApiRevision::Atlas));

        self::assertEquals([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => CheckedDeclaration::class,
            '$comment' => 'response at genesis: First published revision (published 2025-06-01)',
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80, 'description' => 'The title', 'examples' => ['Lamp']],
                'state' => ['type' => 'string', 'enum' => ['pending', 'paid', 'shipped']],
                'total' => ['type' => 'string'],
                'label' => ['type' => 'string'],
            ],
            'required' => ['title', 'state', 'total'],
        ], self::export($describe, ApiRevision::Genesis));
    }

    public function test_the_meta_of_the_revision_is_a_comment(): void
    {
        self::assertSame(
            'response at spring-26: Totals in cents, `state` renamed `status` (published 2026-04-01, deprecated 2026-11-01, sunset 2027-05-01)',
            self::export(static fn(): array => [], ApiRevision::Spring26)['$comment'],
        );
    }

    public function test_an_empty_declaration_has_no_properties(): void
    {
        self::assertSame(['$schema', 'title', '$comment', 'type'], array_keys(self::export(static fn(): array => [], ApiRevision::Atlas)));
    }

    public function test_the_scalars_export_their_keywords(): void
    {
        self::assertEquals([
            'int' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9],
            'float' => ['type' => 'number', 'minimum' => 0.5, 'maximum' => 2],
            'bool' => ['type' => 'boolean', 'deprecated' => true],
            'string' => ['type' => 'string', 'format' => 'email', 'pattern' => '^.+@.+$'],
            'priority' => ['type' => 'integer', 'enum' => [1, 3]],
            'money' => ['$ref' => '#/$defs/money'],
            'any' => [],
        ], self::head(static fn(): array => [
            'int' => Schema::int()->min(1)->max(9),
            'float' => Schema::float()->min(0.5)->max(2),
            'bool' => Schema::bool()->deprecated(),
            'string' => Schema::string()->format('email')->pattern('/^.+@.+$/'),
            'priority' => Schema::enum(Priority::class),
            'money' => new MoneySchema(),
            'any' => Schema::rule(['array']),
        ])['properties']);
    }

    public function test_a_nullable_field_accepts_null(): void
    {
        self::assertEquals([
            'string' => ['type' => ['string', 'null']],
            'status' => ['type' => ['string', 'null'], 'enum' => ['pending', 'paid', 'shipped', null]],
            'money' => ['anyOf' => [['$ref' => '#/$defs/money'], ['type' => 'null']]],
            'customer' => ['anyOf' => [['$ref' => self::CUSTOMER], ['type' => 'null']], 'description' => 'The buyer'],
            'any' => [],
        ], self::head(static fn(): array => [
            'string' => Schema::string()->nullable(),
            'status' => Schema::enum(OrderStatus::class)->nullable(),
            'money' => (new MoneySchema())->nullable(),
            'customer' => Schema::resource(CustomerDeclaration::class)->nullable()->describe('The buyer'),
            'any' => Schema::rule([])->nullable(),
        ])['properties']);
    }

    public function test_dates_export_their_wire_format(): void
    {
        self::assertEquals([
            'day' => ['type' => 'string', 'format' => 'date'],
            'at' => ['type' => 'string', 'format' => 'date-time'],
            'precise' => ['type' => 'string', 'format' => 'date-time'],
            'french' => ['type' => 'string', 'pattern' => '^\d{2}/\d{2}/\d{4}$', '$comment' => 'PHP date format d/m/Y'],
            'named' => ['type' => 'string', '$comment' => 'PHP date format D, d M Y'],
        ], self::head(static fn(): array => [
            'day' => Schema::date(),
            'at' => Schema::dateTime(),
            'precise' => Schema::dateTime()->format(DateTimeInterface::RFC3339_EXTENDED),
            'french' => Schema::date()->format('d/m/Y'),
            'named' => Schema::dateTime()->format('D, d M Y'),
        ])['properties']);
    }

    public function test_lists_and_objects_export_their_items_and_children_with_their_presence(): void
    {
        self::assertEquals([
            'type' => 'array',
            'minItems' => 1,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'sku' => ['type' => 'string'],
                    'qty' => ['type' => ['integer', 'null']],
                    'meta' => ['type' => 'object'],
                ],
                'required' => ['sku', 'meta'],
            ],
        ], self::dig(self::head(static fn(): array => [
            'lines' => Schema::list(Schema::object([
                'sku' => Schema::string(),
                'qty' => Schema::int()->nullable()->when(static fn(): bool => true),
                'meta' => Schema::object([]),
            ]))->min(1),
        ]), 'properties', 'lines'));
    }

    public function test_the_children_of_a_was_schema_are_typed_only(): void
    {
        $describe = static fn(): array => [
            'address' => Schema::object(['city' => Schema::string()])
                ->was(ApiRevision::Winter25, schema: Schema::object(['town' => Schema::string()->nullable()]), down: static fn(): array => []),
        ];

        self::assertEquals(
            ['type' => 'object', 'properties' => ['town' => ['type' => ['string', 'null']]]],
            self::dig(self::export($describe, ApiRevision::Genesis), 'properties', 'address'),
        );
        self::assertEquals(
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            self::dig(self::export($describe, ApiRevision::Atlas), 'properties', 'address'),
        );
    }

    public function test_a_nested_resource_is_a_definition_of_the_same_revision_and_cycles_export(): void
    {
        $exporter = new JsonSchemaExporter(new Shapes());

        $atlas = $exporter->export(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas);
        $genesis = $exporter->export(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis);

        self::assertSame(['$ref' => self::CUSTOMER], self::dig($atlas, 'properties', 'customer'));
        self::assertEquals([
            'Test.Fixtures.Declarations.CustomerDeclaration' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'orders' => ['type' => 'array', 'items' => ['$ref' => '#']],
                ],
                'required' => ['name', 'orders'],
            ],
        ], $atlas['$defs']);
        self::assertEquals([
            'Test.Fixtures.Declarations.CustomerDeclaration' => [
                'type' => 'object',
                'properties' => ['full_name' => ['type' => 'string']],
                'required' => ['full_name'],
            ],
        ], $genesis['$defs']);
    }

    public function test_a_request_requires_its_required_fields_and_leaves_out_those_added_later(): void
    {
        CheckedDeclaration::$kinds = [DeclarationKind::RequestBody];
        CheckedDeclaration::$describe = static fn(): array => [
            'a' => Schema::string()->required(),
            'b' => Schema::string()->required()->sometimes(),
            'c' => Schema::string(),
            'd' => Schema::string()->required()->since(ApiRevision::Atlas, 'x'),
        ];
        $exporter = new JsonSchemaExporter(new Shapes());

        $document = $exporter->export(CheckedDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);

        self::assertSame('request-body at genesis: First published revision (published 2025-06-01)', $document['$comment']);
        self::assertSame(['a', 'b', 'c'], array_keys((array) self::dig($document, 'properties')));
        self::assertSame(['a'], $document['required']);
    }

    public function test_the_rule_keywords_apply_last(): void
    {
        self::assertEquals(
            ['type' => 'integer', 'pattern' => '^\d{5}$', 'description' => 'A postcode'],
            self::dig(self::head(static fn(): array => [
                'zip' => Schema::string()->describe('A postcode')->rules(new PostcodeRule(['type' => 'integer', 'pattern' => '^\d{5}$'])),
            ]), 'properties', 'zip'),
        );
    }

    public function test_visiting_a_compiled_shape_gives_its_export(): void
    {
        $shapes = new Shapes();
        $exporter = new JsonSchemaExporter($shapes);

        self::assertSame(
            $exporter->export(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25),
            $exporter->visit($shapes->for(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25)),
        );
    }

    public function test_the_keywords_of_a_type_leave_out_its_rules_and_null(): void
    {
        $exporter = new JsonSchemaExporter();

        self::assertSame(
            ['type' => 'string', 'minLength' => 2],
            $exporter->typeKeywords(TypeDefinition::of(Kind::String)->withMin(2)->withJsonSchema(['type' => 'integer'])),
        );
        self::assertSame(['$ref' => self::CUSTOMER], $exporter->typeKeywords(TypeDefinition::of(Kind::Object)->withResource(CustomerDeclaration::class)));
    }

    /**
     * @param Closure(): array<string, mixed> $describe
     *
     * @return array<string, mixed>
     */
    private static function export(Closure $describe, ApiRevision $revision): array
    {
        CheckedDeclaration::$describe = $describe;

        return (new JsonSchemaExporter(new Shapes()))->export(CheckedDeclaration::class, DeclarationKind::Response, $revision);
    }

    /**
     * The head response of the declaration `$describe`.
     *
     * @param Closure(): array<string, mixed> $describe
     *
     * @return array<string, mixed>
     */
    private static function head(Closure $describe): array
    {
        return self::export($describe, ApiRevision::Atlas);
    }

    /**
     * The value under `$keys` in `$value`, each level an array.
     */
    private static function dig(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    public function test_a_pattern_is_exported_without_its_delimiters_unless_a_flag_changes_it(): void
    {
        self::assertEquals([
            'slashes' => ['type' => 'string', 'pattern' => '^A-'],
            'unicode' => ['type' => 'string', 'pattern' => '^\\p{Lu}$'],
            'braces' => ['type' => 'string', 'pattern' => '^a/b$'],
            'insensitive' => ['type' => 'string', '$comment' => 'PCRE /^a$/i'],
            'undelimited' => ['type' => 'string', '$comment' => 'PCRE ^a$'],
        ], self::dig(self::head(static fn(): array => [
            'slashes' => Schema::string()->pattern('/^A-/'),
            'unicode' => Schema::string()->pattern('#^\\p{Lu}$#uD'),
            'braces' => Schema::string()->pattern('{^a/b$}'),
            'insensitive' => Schema::string()->pattern('/^a$/i'),
            'undelimited' => Schema::string()->pattern('^a$'),
        ]), 'properties'));
    }

    public function test_the_items_of_a_was_schema_keep_their_nullability(): void
    {
        $describe = static fn(): array => [
            'tags' => Schema::list(Schema::int())->was(ApiRevision::Winter25, schema: Schema::list(Schema::string()->nullable())),
        ];

        self::assertEquals(
            ['type' => 'array', 'items' => ['type' => ['string', 'null']]],
            self::dig(self::export($describe, ApiRevision::Genesis), 'properties', 'tags'),
        );
    }

    public function test_the_keywords_of_a_nullable_type_accept_null(): void
    {
        self::assertSame(['type' => ['string', 'null']], (new JsonSchemaExporter())->typeKeywords(TypeDefinition::of(Kind::String), nullable: true));
    }

    public function test_a_shared_object_is_exported_as_a_component(): void
    {
        $exporter = new JsonSchemaExporter(new Shapes());
        $address = ['$ref' => '#/$defs/Address'];
        $money = ['$ref' => '#/$defs/money'];

        $atlas = $exporter->export(ShippingDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas);

        self::assertSame([
            'from' => ['$ref' => '#/$defs/Test.Fixtures.Declarations.WarehouseDeclaration'],
            'to' => $address,
            'price' => $money,
            'fee' => ['anyOf' => [$money, ['type' => 'null']]],
        ], $atlas['properties']);
        self::assertEquals([
            'Test.Fixtures.Declarations.WarehouseDeclaration' => [
                'type' => 'object',
                'properties' => [
                    'address' => ['anyOf' => [$address, ['type' => 'null']]],
                    'docks' => ['type' => 'array', 'items' => $address],
                ],
                'required' => ['address', 'docks'],
            ],
            'Address' => [
                'type' => 'object',
                'properties' => ['street' => ['type' => 'string'], 'zip' => ['type' => 'string']],
                'required' => ['street', 'zip'],
            ],
            'money' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'integer'], 'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$']], 'required' => ['amount', 'currency']],
        ], $atlas['$defs']);
    }

    public function test_the_history_of_a_component_applies_in_every_reference(): void
    {
        $winter = (new JsonSchemaExporter(new Shapes()))->export(ShippingDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25);

        self::assertSame(
            ['type' => 'object', 'properties' => ['street' => ['type' => 'string']], 'required' => ['street']],
            self::dig($winter, '$defs', 'Address'),
        );
    }

    public function test_a_component_under_the_schema_of_a_was_is_typed_only(): void
    {
        $document = self::export(static fn(): array => [
            'to' => Schema::string()->was(ApiRevision::Winter25, schema: Schema::object(['street' => Schema::string()])->component('OldAddress')),
        ], ApiRevision::Winter25);

        self::assertSame(['$ref' => '#/$defs/OldAddress'], self::dig($document, 'properties', 'to'));
        self::assertSame(['type' => 'object', 'properties' => ['street' => ['type' => 'string']]], self::dig($document, '$defs', 'OldAddress'));
    }

    public function test_two_bodies_under_one_component_name_are_a_declaration_error(): void
    {
        $this->expectExceptionObject(DefinitionException::componentConflict('Address', 'billing.address', 'response', 'atlas'));

        self::export(static fn(): array => [
            'to' => Address::schema(),
            'billing' => Schema::object(['address' => Schema::object(['line' => Schema::string()])->component('Address')]),
        ], ApiRevision::Atlas);
    }

    public function test_the_type_keywords_of_a_component_stay_inline(): void
    {
        $exporter = new JsonSchemaExporter(new Shapes());

        self::assertSame(['type' => 'object', 'properties' => ['street' => ['type' => 'string']]], $exporter->typeKeywords(Schema::object(['street' => Schema::string()])->component('Address')->toType()));
        self::assertSame(['type' => 'object'], array_intersect_key($exporter->typeKeywords((new MoneySchema())->toType()), ['type' => true]));
    }

    public function test_a_component_under_the_head_and_under_a_was_schema_at_one_revision_is_a_conflict(): void
    {
        // presence where the head is in force, typed only under the was() schema (D70)
        $street = static fn(): ObjectSchema => Schema::object(['street' => Schema::string()])->component('Street');
        $this->expectExceptionObject(DefinitionException::componentConflict('Street', 'from', 'response', 'winter-25'));

        self::export(static fn(): array => [
            'to' => $street(),
            'from' => Schema::string()->was(ApiRevision::Winter25, schema: $street()),
        ], ApiRevision::Winter25);
    }

    public function test_a_custom_type_declaring_its_identity_is_exported_in_place(): void
    {
        TypeRegistry::register('relation', RelationSchema::class);
        $describe = static fn(): array => [
            'buyer' => (new RelationSchema('customers'))->was(ApiRevision::Winter25, schema: new RelationSchema('buyers')),
            'product' => new RelationSchema('products'),
            'price' => new MoneySchema(),
        ];
        $head = self::export($describe, ApiRevision::Spring26);
        $old = self::export($describe, ApiRevision::Winter25);

        self::assertSame(['const' => 'customers'], self::dig($head, 'properties', 'buyer', 'properties', 'type'));
        self::assertSame(['const' => 'products'], self::dig($head, 'properties', 'product', 'properties', 'type'));
        self::assertSame(['const' => 'buyers'], self::dig($old, 'properties', 'buyer', 'properties', 'type'));
        $defs = self::dig($head, '$defs');
        self::assertIsArray($defs);
        self::assertSame(['money'], array_keys($defs));
    }

    public function test_a_closed_export_refuses_other_properties_of_every_declared_object(): void
    {
        CheckedDeclaration::$describe = static fn(): array => [
            'to' => Address::schema(),
            'box' => Schema::object([])->nullable(),
            'money' => new MoneySchema(),
            'customer' => Schema::resource(CustomerDeclaration::class),
            'was' => Schema::object(['a' => Schema::object([])])->was(ApiRevision::Spring26, schema: Schema::object(['b' => Schema::object([])])),
        ];
        $closed = (new JsonSchemaExporter(new Shapes(), closed: true))->export(CheckedDeclaration::class, DeclarationKind::Response, ApiRevision::Spring26);
        $open = (new JsonSchemaExporter(new Shapes()))->export(CheckedDeclaration::class, DeclarationKind::Response, ApiRevision::Spring26);

        self::assertFalse(self::dig($closed, 'additionalProperties'));
        self::assertSame(['type' => ['object', 'null'], 'additionalProperties' => false], self::dig($closed, 'properties', 'box'));
        self::assertFalse(self::dig($closed, '$defs', 'Address', 'additionalProperties'));
        self::assertFalse(self::dig($closed, '$defs', 'Test.Fixtures.Declarations.CustomerDeclaration', 'additionalProperties'));
        self::assertSame(['type' => 'object', 'additionalProperties' => false], self::dig($closed, 'properties', 'was', 'properties', 'b'));
        self::assertFalse(self::dig($closed, 'properties', 'was', 'additionalProperties'));
        $money = self::dig($closed, '$defs', 'money');
        self::assertIsArray($money);
        self::assertArrayNotHasKey('additionalProperties', $money);
        self::assertStringNotContainsString('additionalProperties', json_encode($open, JSON_THROW_ON_ERROR));
    }

    public function test_an_empty_schema_is_encoded_as_an_object_and_a_list_keyword_as_a_list(): void
    {
        self::assertSame(
            '{"type":"object","properties":{"any":{},"tags":{"type":"array","items":{}},"code":{"enum":[]}},"required":["any"],"anyOf":[{},{"type":"null"}]}',
            json_encode(JsonSchemaExporter::objects([
                'type' => 'object',
                'properties' => ['any' => [], 'tags' => ['type' => 'array', 'items' => []], 'code' => ['enum' => []]],
                'required' => ['any'],
                'anyOf' => [[], ['type' => 'null']],
            ]), JSON_THROW_ON_ERROR),
        );
    }
}
