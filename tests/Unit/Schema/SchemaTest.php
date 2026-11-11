<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\NonStrictDeclarations;
use Test\TestCase;

final class SchemaTest extends TestCase
{
    public function test_each_scalar_helper_returns_its_descriptor_with_its_source(): void
    {
        self::assertSame(Kind::String, Schema::string('ref')->toType()->kind);
        self::assertSame(['ref'], Schema::string('ref')->source()->path);
        self::assertSame(Kind::Int, Schema::int()->toType()->kind);
        self::assertSame(Kind::Float, Schema::float()->toType()->kind);
        self::assertSame(Kind::Bool, Schema::bool()->toType()->kind);
        self::assertTrue(Schema::bool()->source()->isDeclaredKey());
    }

    public function test_date_and_enum_helpers_return_their_descriptor_with_their_source(): void
    {
        self::assertSame(Kind::Date, Schema::date('placed_on')->toType()->kind);
        self::assertSame(['placed_on'], Schema::date('placed_on')->source()->path);
        self::assertSame(Kind::DateTime, Schema::dateTime()->toType()->kind);
        self::assertSame(OrderStatus::class, Schema::enum(OrderStatus::class, 'state')->toType()->enum);
        self::assertSame(['state'], Schema::enum(OrderStatus::class, 'state')->source()->path);
    }

    public function test_list_and_object_helpers_return_their_descriptor_with_their_source(): void
    {
        $list = Schema::list(Schema::string(), 'tags');
        $object = Schema::object(['street' => Schema::string()], 'shippingAddress');

        self::assertSame(Kind::String, $list->items()->toType()->kind);
        self::assertSame(['tags'], $list->source()->path);
        self::assertSame(['street'], array_keys($object->children()));
        self::assertSame(['shippingAddress'], $object->source()->path);
    }

    public function test_the_rule_helper_returns_a_rule_schema_with_its_rules_and_source(): void
    {
        $schema = Schema::rule(['array'], 'meta');

        self::assertSame(Kind::Any, $schema->toType()->kind);
        self::assertSame(['array'], $schema->declaredRules());
        self::assertSame(['meta'], $schema->source()->path);
        self::assertTrue(Schema::rule([])->source()->isDeclaredKey());
    }

    public function test_an_evaluated_value_is_refused_even_without_strict_types(): void
    {
        foreach (NonStrictDeclarations::evaluatedSources() as $index => $declare) {
            try {
                $declare();
                self::fail("Declaration {$index}: the evaluated value was taken for a key.");
            } catch (DefinitionException $exception) {
                self::assertStringStartsWith('A source is a key, a dotted path or a closure', $exception->getMessage());
            }
        }
    }
}
