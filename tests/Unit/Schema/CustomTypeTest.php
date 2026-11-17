<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Compilation\ShapeSelector;
use Ark4ne\ApiRevisions\Conversion\StepKind;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Money;
use Test\Fixtures\MoneySchema;
use Test\Fixtures\Rules\PostcodeRule;
use Test\TestCase;

/**
 * A custom type carries its type, its rules and its value rules wherever it is used, observed on the
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

    public function test_a_custom_type_converts_to_a_float_with_its_own_converter(): void
    {
        TypeRegistry::register('money', MoneySchema::class);
        $head = ['total' => (new MoneySchema())->was(ApiRevision::Winter25, schema: Schema::float())];

        $converters = TypeRegistry::converters();

        $down = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Down, $converters)->fields['total']->chain;
        $up = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Up, $converters)->fields['total']->chain;

        self::assertSame(StepKind::Registered, $down->steps[0]->kind);
        self::assertSame('custom(money)>float:down', $down->steps[0]->key);
        self::assertSame(StepKind::Registered, $up->steps[0]->kind);
        self::assertSame('float>custom(money):up', $up->steps[0]->key);
        self::assertSame(12.5, $down->bind(TypeRegistry::converters(), [])->apply(new Money(1250, 'EUR')));
        self::assertEquals(new Money(1250, 'EUR'), $up->bind(TypeRegistry::converters(), [])->apply(12.5));
    }

    public function test_a_custom_type_without_registration_has_no_conversion(): void
    {
        $head = ['total' => (new MoneySchema())->was(ApiRevision::Winter25, schema: Schema::float())];

        $down = ShapeSelector::at($head, ApiRevision::Winter25, Direction::Down, TypeRegistry::converters())->fields['total']->chain;

        self::assertSame(StepKind::Missing, $down->steps[0]->kind);
    }
}
