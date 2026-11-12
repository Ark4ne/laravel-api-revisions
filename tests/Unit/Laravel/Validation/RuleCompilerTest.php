<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Validation;

use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Compilation\ShapeSelector;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\Converter;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Laravel\Validation\RuleCompiler;
use Ark4ne\ApiRevisions\Laravel\Validation\RuleMapper;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Schema;
use Closure;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use stdClass;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\OrderInputDeclaration;
use Test\Fixtures\Declarations\StoreOrderDeclaration;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\MoneySchema;
use Test\TestCase;

final class RuleCompilerTest extends TestCase
{
    public function test_rules_use_the_names_of_the_client_revision(): void
    {
        $winter = self::compileClass(OrderInputDeclaration::class, ApiRevision::Winter25);
        $genesis = self::compileClass(OrderInputDeclaration::class, ApiRevision::Genesis);

        self::assertSame(['title', 'state', 'total', 'name'], array_keys($winter));
        self::assertSame(['required', 'string'], $winter['title']);
        self::assertEquals([new Enum(OrderStatus::class)], $winter['state']);
        // an explicit `up:` decides itself: no extra rule
        self::assertSame(['string'], $winter['total']);
        self::assertSame(['numeric'], $genesis['total']);
        self::assertSame(['required', 'string'], $genesis['name']);
    }

    public function test_the_fields_added_after_the_revision_are_not_validated(): void
    {
        self::assertSame(['title', 'status', 'total', 'name'], array_keys(self::compileClass(OrderInputDeclaration::class, ApiRevision::Spring26)));
        self::assertSame(['title', 'status', 'total', 'first_name', 'last_name', 'priority'], array_keys(self::compileClass(OrderInputDeclaration::class, ApiRevision::Atlas)));
    }

    public function test_a_string_to_int_change_adds_integer_to_the_old_rules(): void
    {
        self::assertSame(['string', 'integer'], self::compileClass(StoreOrderDeclaration::class, ApiRevision::Winter25)['total']);
        self::assertSame(['integer'], self::compileClass(StoreOrderDeclaration::class, ApiRevision::Spring26)['total']);
        self::assertSame(['string', 'numeric'], self::compile([
            'price' => Schema::float()->was(ApiRevision::Winter25, schema: Schema::string()),
        ], ApiRevision::Winter25)['price']);
    }

    public function test_a_change_to_an_enum_adds_its_values_to_the_old_rules(): void
    {
        $rules = self::compile([
            'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, schema: Schema::string()),
        ], ApiRevision::Genesis)['status'];

        self::assertCount(2, $rules);
        self::assertSame('string', $rules[0]);
        self::assertInstanceOf(In::class, $rules[1]);
        self::assertSame('in:"pending","paid","shipped"', (string) $rules[1]);
    }

    public function test_only_the_first_up_conversion_adds_its_rules(): void
    {
        // Genesis: string → float (numeric), then float → int: the input is a string
        $rules = self::compile([
            'total' => Schema::int()
                ->was(ApiRevision::Winter25, schema: Schema::float())
                ->was(ApiRevision::Genesis, schema: Schema::string()),
        ], ApiRevision::Genesis)['total'];

        self::assertSame(['string', 'numeric'], $rules);
    }

    public function test_presence_rules_come_first_as_declared(): void
    {
        $rules = self::compile([
            'all' => Schema::string()->sometimes()->required()->nullable(),
            'nullable' => Schema::int()->nullable(),
            'plain' => Schema::bool(),
        ], ApiRevision::Atlas);

        self::assertSame(['sometimes', 'required', 'nullable', 'string'], $rules['all']);
        self::assertSame(['nullable', 'integer'], $rules['nullable']);
        self::assertSame(['boolean'], $rules['plain']);
    }

    public function test_the_nullability_is_the_one_in_force_at_the_revision(): void
    {
        $head = ['notes' => Schema::string()->was(ApiRevision::Winter25, schema: Schema::string()->nullable())];

        self::assertSame(['nullable', 'string'], self::compile($head, ApiRevision::Winter25)['notes']);
        self::assertSame(['string'], self::compile($head, ApiRevision::Atlas)['notes']);
    }

    public function test_nested_rules_use_dotted_keys(): void
    {
        $rules = self::compile([
            'address' => Schema::object([
                'zip' => Schema::string()->required()->was(ApiRevision::Winter25, name: 'postcode'),
            ]),
            'lines' => Schema::list(Schema::object([
                'sku' => Schema::string()->required(),
            ]))->min(1),
            'tags' => Schema::list(Schema::string()->max(20)),
        ], ApiRevision::Winter25);

        self::assertSame([
            'address' => ['array'],
            'address.postcode' => ['required_unless:address,null', 'string'],
            'lines' => ['list', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.sku' => ['required', 'string'],
            'tags' => ['list'],
            'tags.*' => ['string', 'max:20'],
        ], $rules);
    }

    public function test_a_nested_resource_gets_the_rules_of_its_request_shape_at_the_revision(): void
    {
        $rules = self::compileClass(StoreOrderDeclaration::class, ApiRevision::Winter25);

        self::assertSame(['array'], $rules['customer']);
        self::assertSame(['required_unless:customer,null', 'string'], $rules['customer.full_name']);
        self::assertArrayNotHasKey('customer.name', $rules);
        self::assertSame(['required_unless:customer,null', 'string'], self::compileClass(StoreOrderDeclaration::class, ApiRevision::Atlas)['customer.name']);
    }

    public function test_the_query_shape_has_its_own_rules(): void
    {
        $shapes = new Shapes();
        $shape = $shapes->for(StoreOrderDeclaration::class, DeclarationKind::RequestQuery, ApiRevision::Genesis);

        $rules = (new RuleCompiler($shapes))->compile($shape, StoreOrderDeclaration::describeRevisioned(DeclarationKind::RequestQuery, null));

        self::assertEquals(['state' => [new Enum(OrderStatus::class)]], $rules);
    }

    public function test_the_schema_of_a_was_gives_the_children_and_items_at_the_revision(): void
    {
        $head = [
            'address' => Schema::object([
                'street' => Schema::string(),
            ])->was(ApiRevision::Winter25, schema: Schema::object([
                'line' => Schema::string()->required()->min(2)->rules('max:80'),
                'city' => Schema::object(['zip' => Schema::string()]),
            ]), up: static fn(array $address): array => ['street' => $address['line'] ?? '']),
            'codes' => Schema::list(Schema::int())->was(ApiRevision::Winter25, schema: Schema::list(Schema::string())),
        ];

        $rules = self::compile($head, ApiRevision::Genesis);

        self::assertSame([
            'address' => ['array'],
            'address.line' => ['required_unless:address,null', 'string', 'min:2', 'max:80'],
            'address.city' => ['array'],
            'address.city.zip' => ['string'],
            'codes' => ['list'],
            'codes.*' => ['string'],
        ], $rules);
        self::assertSame(['address', 'address.street', 'codes', 'codes.*'], array_keys(self::compile($head, ApiRevision::Atlas)));
    }

    public function test_a_resource_in_the_schema_of_a_was_gets_the_rules_of_its_class(): void
    {
        $rules = self::compile([
            'buyer' => Schema::string()->was(ApiRevision::Winter25, schema: Schema::resource(CustomerDeclaration::class), up: static fn(): string => 'Ada'),
        ], ApiRevision::Winter25);

        self::assertSame(['buyer' => ['array'], 'buyer.full_name' => ['required_unless:buyer,null', 'string']], $rules);
    }

    public function test_a_composite_on_one_side_only_is_refused(): void
    {
        $shape = self::shape(['address' => Schema::object(['zip' => Schema::string()])], ApiRevision::Atlas);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("The descriptors projected for object do not match the field 'address' of its shape.");

        (new RuleCompiler(new Shapes()))->compile($shape, ['address' => Schema::string()]);
    }

    /**
     * @return iterable<string, array{Descriptor<mixed>, Descriptor<mixed>}>
     */
    public static function mismatches(): iterable
    {
        yield 'a list for an object' => [Schema::list(Schema::string()), Schema::object([])];
        yield 'an object for a scalar' => [Schema::string(), Schema::object([])];
        yield 'a list for a scalar' => [Schema::string(), Schema::list(Schema::string())];
    }

    /**
     * @param Descriptor<mixed> $declared
     * @param Descriptor<mixed> $given
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mismatches')]
    public function test_descriptors_of_another_composite_are_refused(Descriptor $declared, Descriptor $given): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("The descriptors projected for object do not match the field 'tags' of its shape.");

        (new RuleCompiler(new Shapes()))->compile(self::shape(['tags' => $declared], ApiRevision::Atlas), ['tags' => $given]);
    }

    public function test_a_missing_descriptor_names_the_class_of_the_shape(): void
    {
        $shapes = new Shapes();

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('The descriptors projected for ' . StoreOrderDeclaration::class . " do not match the field 'title' of its shape.");

        (new RuleCompiler($shapes))->compile($shapes->for(StoreOrderDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Atlas), []);
    }

    public function test_the_extra_rules_come_from_the_converters_given(): void
    {
        $converter = new class implements Converter {
            public function convert(mixed $value, mixed $subject = null): mixed
            {
                return is_numeric($value) ? (int) $value : 0;
            }

            public function lossless(): bool
            {
                return false;
            }

            public function extraRules(): array
            {
                return ['digits_between:1,9'];
            }
        };
        $converters = Converters::builtIn()->register(Kind::String, Kind::Int, Direction::Up, static fn(): Converter => $converter);
        $head = ['total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string())];

        $rules = (new RuleCompiler(new Shapes(), $converters))->compile(self::shape($head, ApiRevision::Winter25), $head);

        self::assertSame(['total' => ['string', 'digits_between:1,9']], $rules);
    }

    public function test_a_required_child_is_required_only_when_a_parent_the_client_may_leave_out_is_sent(): void
    {
        $rules = self::compile([
            'always' => Schema::object([
                'a' => Schema::string()->required(),
                'deep' => Schema::object(['b' => Schema::string()->required()]),
                'kept' => Schema::object(['c' => Schema::string()->required()])->required(),
            ])->required(),
            'maybe' => Schema::object(['d' => Schema::string()->required()])->required()->sometimes(),
            'empty' => Schema::object(['e' => Schema::string()->required()])->required()->nullable(),
            'lines' => Schema::list(Schema::object(['f' => Schema::string()->required()])),
            'holes' => Schema::list(Schema::object(['g' => Schema::string()->required()])->nullable()),
            'old' => Schema::object([])->was(ApiRevision::Winter25, schema: Schema::object([
                'h' => Schema::object(['i' => Schema::string()->required()])->required(),
                'items' => Schema::list(Schema::object(['j' => Schema::string()->required()])),
            ])->required(), up: static fn(): array => []),
        ], ApiRevision::Winter25);

        self::assertSame(['required', 'string'], $rules['always.a']);
        self::assertSame(['required_unless:always.deep,null', 'string'], $rules['always.deep.b']);
        self::assertSame(['required', 'string'], $rules['always.kept.c']);
        self::assertSame(['required_unless:maybe,null', 'string'], $rules['maybe.d']);
        self::assertSame(['required_unless:empty,null', 'string'], $rules['empty.e']);
        self::assertSame(['required', 'string'], $rules['lines.*.f']);
        self::assertSame(['required_unless:holes.*,null', 'string'], $rules['holes.*.g']);
        self::assertSame(['required_unless:old,null', 'array'], $rules['old.h']);
        self::assertSame(['required_unless:old.h,null', 'string'], $rules['old.h.i']);
        self::assertSame(['required', 'string'], $rules['old.items.*.j']);
    }

    public function test_a_mapper_for_no_kind_is_refused(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Unknown kind 'text': a rule mapper is keyed by a Kind value.");

        new RuleCompiler(new Shapes(), mappers: ['text' => new \Ark4ne\ApiRevisions\Laravel\Validation\Mappers\FixedRules()]);
    }

    public function test_user_rules_are_kept_as_given(): void
    {
        $rule = new stdClass();
        $closure = static function (string $attribute, mixed $value, Closure $fail): void {};

        $rules = self::compile([
            'title' => Schema::string()->max(255)->rules('alpha_dash', $rule)->rules($closure),
            'meta' => Schema::rule(['array', $rule]),
            'price' => new MoneySchema(),
        ], ApiRevision::Atlas);

        self::assertSame(['string', 'max:255', 'alpha_dash', $rule, $closure], $rules['title']);
        self::assertSame(['array', $rule], $rules['meta']);
        self::assertSame(['array', 'required_array_keys:amount,currency'], $rules['price']);
    }

    public function test_the_rules_of_a_was_schema_replace_those_of_the_head(): void
    {
        $head = ['code' => Schema::string()->rules('alpha')->was(ApiRevision::Winter25, schema: Schema::string()->rules('digits:5'))];

        self::assertSame(['string', 'digits:5'], self::compile($head, ApiRevision::Winter25)['code']);
        self::assertSame(['string', 'alpha'], self::compile($head, ApiRevision::Atlas)['code']);
    }

    public function test_dates_follow_their_format(): void
    {
        $rules = self::compile([
            'day' => Schema::date(),
            'at' => Schema::dateTime(),
            'local' => Schema::date()->format('d/m/Y'),
        ], ApiRevision::Atlas);

        self::assertSame(['date_format:Y-m-d'], $rules['day']);
        self::assertSame(['date_format:Y-m-d\TH:i:sP'], $rules['at']);
        self::assertSame(['date_format:d/m/Y'], $rules['local']);
    }

    public function test_two_fields_under_one_name_merge_their_rules(): void
    {
        $rules = self::compile([
            'state' => Schema::string()->until(ApiRevision::Winter25),
            'status' => Schema::int()->was(ApiRevision::Winter25, name: 'state', schema: Schema::int()->min(1)),
        ], ApiRevision::Winter25);

        self::assertSame(['state' => ['string', 'integer', 'min:1']], $rules);
    }

    public function test_a_mapper_given_replaces_the_default_of_its_kind(): void
    {
        $mapper = new class implements RuleMapper {
            public function rules(TypeDefinition $type): array
            {
                return ['custom-string'];
            }
        };

        $rules = (new RuleCompiler(new Shapes(), mappers: ['string' => $mapper]))
            ->compile(self::shape(['title' => Schema::string(), 'count' => Schema::int()], ApiRevision::Atlas), ['title' => Schema::string(), 'count' => Schema::int()]);

        self::assertSame(['title' => ['custom-string'], 'count' => ['integer']], $rules);
    }

    public function test_a_response_shape_has_no_rules(): void
    {
        $head = ['title' => Schema::string()];

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('The shape of object is a response shape (down): only a request shape (up) has validation rules.');

        (new RuleCompiler(new Shapes()))->compile(ShapeSelector::at($head, ApiRevision::Atlas, Direction::Down), $head);
    }

    public function test_descriptors_missing_a_field_of_the_shape_are_refused(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("The descriptors projected for object do not match the field 'title' of its shape.");

        (new RuleCompiler(new Shapes()))->compile(self::shape(['title' => Schema::string()], ApiRevision::Atlas), []);
    }

    public function test_descriptors_without_the_was_schema_of_the_shape_are_refused(): void
    {
        $shape = self::shape(['code' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string())], ApiRevision::Winter25);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("The descriptors projected for object do not match the field 'code' of its shape.");

        (new RuleCompiler(new Shapes()))->compile($shape, ['code' => Schema::int()]);
    }

    /**
     * @param array<string, Descriptor<mixed>> $head
     *
     * @return array<string, list<mixed>>
     */
    private static function compile(array $head, Revision $revision): array
    {
        return (new RuleCompiler(new Shapes()))->compile(self::shape($head, $revision), $head);
    }

    /**
     * @param class-string<\Ark4ne\ApiRevisions\Schema\Revisioned> $class
     *
     * @return array<string, list<mixed>>
     */
    private static function compileClass(string $class, Revision $revision): array
    {
        $shapes = new Shapes();

        return (new RuleCompiler($shapes))->compile(
            $shapes->for($class, DeclarationKind::RequestBody, $revision),
            $class::describeRevisioned(DeclarationKind::RequestBody, null),
        );
    }

    /**
     * @param array<string, Descriptor<mixed>> $head
     */
    private static function shape(array $head, Revision $revision): Shape
    {
        return ShapeSelector::at(
            array_map(static fn(Descriptor $descriptor): Descriptor => $descriptor->configured(new Configuration()), $head),
            $revision,
            Direction::Up,
        );
    }
}
