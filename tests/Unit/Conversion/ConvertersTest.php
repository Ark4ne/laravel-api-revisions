<?php

declare(strict_types=1);

namespace Test\Unit\Conversion;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\BoolToInt;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\BoolToString;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\EnumToBacked;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\FloatToInt;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\FloatToString;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\IntToBool;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\IntToFloat;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\IntToString;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\NumericStringToFloat;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\NumericStringToInt;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\StringToBool;
use Ark4ne\ApiRevisions\Conversion\Converter;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Enums\Priority;
use Test\TestCase;

/**
 * The table of model.md §5, row by row and per direction.
 */
final class ConvertersTest extends TestCase
{
    private static function type(Kind $kind): TypeDefinition
    {
        return TypeDefinition::of($kind);
    }

    private static function orderStatus(): TypeDefinition
    {
        return TypeDefinition::of(Kind::Enum)->withEnum(OrderStatus::class, Kind::String, ['pending', 'paid', 'shipped']);
    }

    private static function priority(): TypeDefinition
    {
        return TypeDefinition::of(Kind::Enum)->withEnum(Priority::class, Kind::Int, [1, 3]);
    }

    private static function convert(TypeDefinition $from, TypeDefinition $to, Direction $direction, mixed $value): mixed
    {
        $converter = Converters::builtIn()->find($from, $to, $direction);
        self::assertInstanceOf(Converter::class, $converter);

        return $converter->convert($value);
    }

    /**
     * @return iterable<string, array{Direction}>
     */
    public static function directions(): iterable
    {
        yield 'down' => [Direction::Down];
        yield 'up' => [Direction::Up];
    }

    #[DataProvider('directions')]
    public function test_int_to_float_is_automatic(Direction $direction): void
    {
        $converter = Converters::builtIn()->find(self::type(Kind::Int), self::type(Kind::Float), $direction);

        self::assertInstanceOf(IntToFloat::class, $converter);
        self::assertTrue($converter->lossless());
        self::assertSame(12.0, $converter->convert(12));
    }

    #[DataProvider('directions')]
    public function test_float_to_int_is_automatic_and_flagged_lossy(Direction $direction): void
    {
        $converter = Converters::builtIn()->find(self::type(Kind::Float), self::type(Kind::Int), $direction);

        self::assertNotNull($converter);
        self::assertFalse($converter->lossless());
        self::assertSame(12, $converter->convert(12.7));
    }

    #[DataProvider('directions')]
    public function test_numeric_string_to_float_requires_the_numeric_rule(Direction $direction): void
    {
        $converter = Converters::builtIn()->find(self::type(Kind::String), self::type(Kind::Float), $direction);

        self::assertNotNull($converter);
        self::assertSame(['numeric'], $converter->extraRules());
        self::assertFalse($converter->lossless());
        self::assertSame(12.5, $converter->convert('12.5'));
    }

    #[DataProvider('directions')]
    public function test_an_unknown_enum_value_throws_a_conversion_exception(Direction $direction): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage(sprintf("'lost' is not a value of the enum %s.", OrderStatus::class));

        self::convert(self::type(Kind::String), self::orderStatus(), $direction, 'lost');
    }

    /**
     * Every automatic pair of model.md §5: from, to, value, expected, lossless.
     *
     * @return iterable<string, array{TypeDefinition, TypeDefinition, mixed, mixed, bool}>
     */
    public static function table(): iterable
    {
        $rows = [
            'int → float' => [self::type(Kind::Int), self::type(Kind::Float), 3, 3.0, true],
            'int → string' => [self::type(Kind::Int), self::type(Kind::String), 3, '3', true],
            'float → string' => [self::type(Kind::Float), self::type(Kind::String), 0.1, '0.1', true],
            'bool → string' => [self::type(Kind::Bool), self::type(Kind::String), false, '0', true],
            'bool → int' => [self::type(Kind::Bool), self::type(Kind::Int), true, 1, true],
            'string → int' => [self::type(Kind::String), self::type(Kind::Int), '12.7', 12, false],
            'string → float' => [self::type(Kind::String), self::type(Kind::Float), '1e3', 1000.0, false],
            'float → int' => [self::type(Kind::Float), self::type(Kind::Int), -1.5, -1, false],
            'int → bool' => [self::type(Kind::Int), self::type(Kind::Bool), 0, false, false],
            'string → bool' => [self::type(Kind::String), self::type(Kind::Bool), 'no', false, false],
            'string enum → string' => [self::orderStatus(), self::type(Kind::String), OrderStatus::Paid, 'paid', true],
            'int enum → string' => [self::priority(), self::type(Kind::String), Priority::High, '3', true],
            'int enum → int' => [self::priority(), self::type(Kind::Int), Priority::High, 3, true],
            'string → string enum' => [self::type(Kind::String), self::orderStatus(), 'paid', OrderStatus::Paid, true],
            'string → int enum' => [self::type(Kind::String), self::priority(), '3', Priority::High, true],
            'int → int enum' => [self::type(Kind::Int), self::priority(), 1, Priority::Low, true],
        ];
        foreach (Direction::cases() as $direction) {
            foreach ($rows as $label => [$from, $to, $value, $expected, $lossless]) {
                yield "{$label} {$direction->value}" => [$from, $to, $value, $expected, $lossless, $direction];
            }
        }
    }

    #[DataProvider('table')]
    public function test_every_pair_of_the_table_converts_in_both_directions(TypeDefinition $from, TypeDefinition $to, mixed $value, mixed $expected, bool $lossless, Direction $direction): void
    {
        $converter = Converters::builtIn()->find($from, $to, $direction);

        self::assertNotNull($converter);
        self::assertSame($expected, $converter->convert($value));
        self::assertSame($lossless, $converter->lossless());
    }

    /**
     * @return iterable<string, array{TypeDefinition, TypeDefinition}>
     */
    public static function notAutomatic(): iterable
    {
        yield 'string enum → int' => [self::orderStatus(), self::type(Kind::Int)];
        yield 'enum → other enum' => [self::orderStatus(), self::priority()];
        yield 'float → enum' => [self::type(Kind::Float), self::priority()];
        yield 'bool → enum' => [self::type(Kind::Bool), self::orderStatus()];
        yield 'enum → float' => [self::priority(), self::type(Kind::Float)];
        yield 'float → bool' => [self::type(Kind::Float), self::type(Kind::Bool)];
        yield 'object → string' => [self::type(Kind::Object), self::type(Kind::String)];
        yield 'string → list' => [self::type(Kind::String), TypeDefinition::of(Kind::List)->withItems(self::type(Kind::String))];
        yield 'date → string' => [self::type(Kind::Date), self::type(Kind::String)];
        yield 'any → string' => [self::type(Kind::Any), self::type(Kind::String)];
        yield 'same kind' => [self::type(Kind::String), self::type(Kind::String)];
    }

    #[DataProvider('notAutomatic')]
    public function test_other_pairs_need_an_explicit_closure(TypeDefinition $from, TypeDefinition $to): void
    {
        self::assertNull(Converters::builtIn()->find($from, $to, Direction::Down));
        self::assertNull(Converters::builtIn()->find($from, $to, Direction::Up));
    }

    public function test_a_registered_factory_serves_its_pair_and_direction_only(): void
    {
        $converter = new IntToFloat();
        $builtIn = Converters::builtIn();

        $registry = $builtIn->register(Kind::Date, Kind::String, Direction::Down, static fn(TypeDefinition $from, TypeDefinition $to): Converter => $converter);

        self::assertSame($converter, $registry->find(self::type(Kind::Date), self::type(Kind::String), Direction::Down));
        self::assertNull($registry->find(self::type(Kind::Date), self::type(Kind::String), Direction::Up));
        self::assertNull($builtIn->find(self::type(Kind::Date), self::type(Kind::String), Direction::Down));
    }

    public function test_a_factory_receives_both_types_and_may_decline(): void
    {
        $received = [];
        $registry = Converters::builtIn()->register(Kind::Int, Kind::Float, Direction::Up, static function (TypeDefinition $from, TypeDefinition $to) use (&$received): ?Converter {
            $received = [$from, $to];

            return null;
        });

        self::assertNull($registry->find(self::type(Kind::Int), self::type(Kind::Float), Direction::Up));
        self::assertEquals([self::type(Kind::Int), self::type(Kind::Float)], $received);
        self::assertInstanceOf(IntToFloat::class, $registry->find(self::type(Kind::Int), self::type(Kind::Float), Direction::Down));
    }

    public function test_the_key_of_a_pair_is_serializable_text(): void
    {
        self::assertSame('int>float:down', Converters::key(Kind::Int, Kind::Float, Direction::Down));
        self::assertSame('string>enum:up', Converters::key(Kind::String, Kind::Enum, Direction::Up));
    }

    /**
     * @return iterable<string, array{Converter}>
     */
    public static function builtIns(): iterable
    {
        foreach ([
            new IntToFloat(), new IntToString(), new FloatToString(), new BoolToString(), new BoolToInt(),
            new NumericStringToInt(), new NumericStringToFloat(), new FloatToInt(), new IntToBool(), new StringToBool(),
            new EnumToBacked(Kind::String), new EnumToBacked(Kind::Int),
        ] as $converter) {
            yield $converter::class . ' ' . spl_object_id($converter) => [$converter];
        }
    }

    #[DataProvider('builtIns')]
    public function test_a_value_of_another_type_is_returned_as_it_is(Converter $converter): void
    {
        $value = new stdClass();

        self::assertSame($value, $converter->convert($value));
    }

    public function test_a_custom_type_is_keyed_by_its_name(): void
    {
        self::assertSame('custom(money)>float:down', Converters::key('money', Kind::Float, Direction::Down));
        self::assertSame('float>custom(money):up', Converters::key(Kind::Float, 'money', Direction::Up));
        self::assertSame(
            'custom(money)>custom(percent):up',
            Converters::keyOf(TypeDefinition::custom('money'), TypeDefinition::custom('percent'), Direction::Up),
        );
        self::assertSame('int>float:down', Converters::keyOf(TypeDefinition::of(Kind::Int), TypeDefinition::of(Kind::Float), Direction::Down));
    }

    public function test_two_custom_types_do_not_overwrite_each_other(): void
    {
        $toMoney = new IntToFloat();
        $toPercent = new IntToFloat();
        $registry = (new Converters())
            ->register('money', Kind::Float, Direction::Down, static fn(): Converter => $toMoney)
            ->register('percent', Kind::Float, Direction::Down, static fn(): Converter => $toPercent);
        $float = TypeDefinition::of(Kind::Float);

        self::assertSame($toMoney, $registry->find(TypeDefinition::custom('money'), $float, Direction::Down));
        self::assertSame($toPercent, $registry->find(TypeDefinition::custom('percent'), $float, Direction::Down));
        self::assertNull($registry->find(TypeDefinition::custom('euro'), $float, Direction::Down));
    }

    public function test_the_custom_kind_without_name_is_refused(): void
    {
        $this->expectExceptionObject(DefinitionException::invalidConversionSide('custom'));

        (new Converters())->register(Kind::Float, Kind::Custom, Direction::Down, static fn(): Converter => new IntToFloat());
    }

    public function test_an_empty_custom_type_name_is_refused(): void
    {
        $this->expectExceptionObject(DefinitionException::invalidConversionSide(''));

        (new Converters())->register('', Kind::Float, Direction::Down, static fn(): Converter => new IntToFloat());
    }
}
