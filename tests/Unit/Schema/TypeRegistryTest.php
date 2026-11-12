<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\IntSchema;
use Ark4ne\ApiRevisions\Schema\StringSchema;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\Fixtures\FloatToMoney;
use Test\Fixtures\MoneySchema;
use Test\Fixtures\MoneyToFloat;
use Test\TestCase;

final class TypeRegistryTest extends TestCase
{
    public function test_a_registered_type_is_built_with_its_arguments(): void
    {
        TypeRegistry::register('money', MoneySchema::class);

        $positional = TypeRegistry::make('money', ['total', 'EUR']);
        $named = TypeRegistry::make('money', ['currency' => 'USD']);

        self::assertTrue(TypeRegistry::has('money'));
        self::assertInstanceOf(MoneySchema::class, $positional);
        self::assertSame(['total'], $positional->source()->path);
        self::assertInstanceOf(MoneySchema::class, $named);
        self::assertTrue($named->source()->isDeclaredKey());
        self::assertNotSame(TypeRegistry::make('money', []), TypeRegistry::make('money', []));
    }

    public function test_a_name_may_use_capitals_digits_and_underscores(): void
    {
        TypeRegistry::register('Money_2', MoneySchema::class);

        self::assertTrue(TypeRegistry::has('Money_2'));
        self::assertFalse(TypeRegistry::has('money_2'));
    }

    public function test_registering_a_name_again_replaces_the_type(): void
    {
        TypeRegistry::register('amount', MoneySchema::class);
        TypeRegistry::register('amount', StringSchema::class);

        self::assertInstanceOf(StringSchema::class, TypeRegistry::make('amount', []));
    }

    public function test_an_unknown_type_cannot_be_made(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Unknown type 'money': register it with TypeRegistry::register('money', …).");

        TypeRegistry::make('money', []);
    }

    public function test_flush_forgets_every_type(): void
    {
        TypeRegistry::register('money', MoneySchema::class);

        TypeRegistry::flush();

        self::assertFalse(TypeRegistry::has('money'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'not a method name' => ['big-money'];
        yield 'leading digit' => ['1money'];
        yield 'trailing newline' => ["money\n"];
        yield 'a built-in factory' => ['string'];
        yield 'a built-in factory, other case' => ['Object'];
        yield 'a group' => ['since'];
        yield 'the magic method' => ['__callStatic'];
    }

    #[DataProvider('invalidNames')]
    public function test_a_name_schema_cannot_serve_is_refused(string $name): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Invalid type name '{$name}'");

        TypeRegistry::register($name, MoneySchema::class);
    }

    public function test_a_class_that_is_not_a_descriptor_is_refused(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("The custom type 'money' must extend Ark4ne\\ApiRevisions\\Schema\\Descriptor, got stdClass.");

        TypeRegistry::register('money', stdClass::class); // @phpstan-ignore argument.type
    }

    public function test_an_abstract_descriptor_is_refused(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("The custom type 'money' must extend Ark4ne\\ApiRevisions\\Schema\\Descriptor, got Ark4ne\\ApiRevisions\\Schema\\Descriptor.");

        TypeRegistry::register('money', Descriptor::class);
    }

    public function test_a_missing_class_is_refused(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("The custom type 'money' must extend Ark4ne\\ApiRevisions\\Schema\\Descriptor, got App\\Missing.");

        TypeRegistry::register('money', 'App\Missing'); // @phpstan-ignore argument.type
    }

    public function test_the_converters_add_the_conversions_of_the_registered_types_to_the_built_in_ones(): void
    {
        $money = TypeDefinition::custom('money');
        $float = TypeDefinition::of(Kind::Float);
        $int = TypeDefinition::of(Kind::Int);

        self::assertNull(TypeRegistry::converters()->find($money, $float, Direction::Down));

        TypeRegistry::register('amount', IntSchema::class);
        TypeRegistry::register('money', MoneySchema::class);
        $converters = TypeRegistry::converters();

        self::assertInstanceOf(MoneyToFloat::class, $converters->find($money, $float, Direction::Down));
        self::assertInstanceOf(FloatToMoney::class, $converters->find($float, $money, Direction::Up));
        self::assertNotNull($converters->find($int, $float, Direction::Down));
    }

    public function test_the_converters_extend_the_given_registry(): void
    {
        TypeRegistry::register('money', MoneySchema::class);

        $converters = TypeRegistry::converters(new Converters());

        self::assertNotNull($converters->find(TypeDefinition::custom('money'), TypeDefinition::of(Kind::Float), Direction::Down));
        self::assertNull($converters->find(TypeDefinition::of(Kind::Int), TypeDefinition::of(Kind::Float), Direction::Down));
    }
}
