<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Money;
use Test\Fixtures\MoneySchema;
use Test\Fixtures\Rules\PostcodeRule;
use Test\TestCase;

/**
 * A custom type carries its type, its rules and its value rules wherever it is used (model.md §3), observed on the
 * `MoneySchema` fixture.
 */
final class CustomTypeTest extends TestCase
{
    public function test_a_custom_type_declares_its_name_and_json_schema_fragment_apart_from_its_rules(): void
    {
        $type = (new MoneySchema())->describe('Total')->rules(new PostcodeRule(['description' => 'cents']))->toType();

        self::assertSame(Kind::Custom, $type->kind);
        self::assertSame('money', $type->name);
        self::assertSame('Total', $type->description);
        self::assertSame('object', $type->fragment['type']);
        self::assertSame(['amount', 'currency'], $type->fragment['required']);
        self::assertSame(['description' => 'cents'], $type->jsonSchema);
    }

    public function test_a_custom_type_carries_its_rules_before_the_added_ones(): void
    {
        self::assertSame(['array', 'required_array_keys:amount,currency', 'filled'], (new MoneySchema())->rules('filled')->declaredRules());
    }

    public function test_a_custom_type_has_every_modifier_of_a_descriptor(): void
    {
        TypeRegistry::register('money', MoneySchema::class);

        $schema = TypeRegistry::make('money', ['total'])->nullable()->since(ApiRevision::Winter25)->whenHas();

        self::assertTrue($schema->isNullable());
        self::assertSame(ApiRevision::Winter25, $schema->lifetime()->since);
        self::assertTrue($schema->isConditional());
    }

    public function test_a_custom_type_normalizes_and_serializes_its_values(): void
    {
        $schema = new MoneySchema(currency: 'EUR');

        $money = $schema->normalize(['amount' => 1250]);

        self::assertEquals(new Money(1250, 'EUR'), $money);
        self::assertSame(['amount' => 1250, 'currency' => 'EUR'], $schema->serialize($money));
        self::assertRefused($schema->normalize(['amount' => '12.50']), ['amount' => '12.50'], Kind::Custom, null);
    }
}
