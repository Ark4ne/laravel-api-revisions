<?php

declare(strict_types=1);

namespace Test\Unit\Conversion;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\NumericStringToFloat;
use Ark4ne\ApiRevisions\Conversion\ClosureConverter;
use Ark4ne\ApiRevisions\Conversion\ConversionChain;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Conversion\StepKind;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Enums\Priority;
use Test\Fixtures\MoneySchema;
use Test\TestCase;

final class ConversionChainTest extends TestCase
{
    /**
     * `total` of model.md §4.2: int at the head, a string `was(Winter25)` with closures (index 0), a float
     * `was(Genesis)` converted automatically (index 1).
     */
    public function test_a_down_chain_converts_from_the_head_to_the_oldest_shape(): void
    {
        $chain = ConversionChain::empty(Direction::Down)
            ->withStep(self::int(), self::string(), 0, true, Converters::builtIn())
            ->withStep(self::string(), self::float(), 1, false, Converters::builtIn())
            ->bind(Converters::builtIn(), [0 => self::down(), 1 => null]);

        self::assertSame([StepKind::Explicit, StepKind::Registered], array_map(static fn($step) => $step->kind, $chain->steps));
        self::assertSame(12.5, $chain->apply(1250));
    }

    public function test_an_up_chain_converts_from_the_client_shape_to_the_head(): void
    {
        $chain = ConversionChain::empty(Direction::Up)
            ->withStep(self::float(), self::string(), 1, false, Converters::builtIn())
            ->withStep(self::string(), self::int(), 0, true, Converters::builtIn())
            ->bind(Converters::builtIn(), [0 => self::up(), 1 => null]);

        self::assertSame(1250, $chain->apply(12.5));
    }

    public function test_a_chain_survives_serialization(): void
    {
        $planned = ConversionChain::empty(Direction::Down)
            ->withStep(self::int(), self::string(), 0, true, Converters::builtIn())
            ->withStep(self::string(), self::float(), 1, false, Converters::builtIn());

        $restored = unserialize(serialize($planned));

        self::assertInstanceOf(ConversionChain::class, $restored);
        self::assertEquals($planned, $restored);
        self::assertSame(12.5, $restored->bind(Converters::builtIn(), [0 => self::down()])->apply(1250));
    }

    public function test_a_planned_chain_holds_references_only(): void
    {
        $chain = ConversionChain::empty(Direction::Down)
            ->withStep(self::int(), self::string(), 0, true, Converters::builtIn())
            ->withStep(self::string(), self::float(), 1, false, Converters::builtIn());

        self::assertSame(['int>string:down', 'string>float:down'], array_map(static fn($step) => $step->key, $chain->steps));
        self::assertSame([0, 1], array_map(static fn($step) => $step->change, $chain->steps));
        self::assertSame([null, null], array_map(static fn($step) => $step->converter, $chain->steps));
    }

    public function test_bind_attaches_the_converter_of_each_step(): void
    {
        $down = self::down();

        $chain = ConversionChain::empty(Direction::Down)
            ->withStep(self::int(), self::string(), 0, true, Converters::builtIn())
            ->withStep(self::string(), self::float(), 1, false, Converters::builtIn())
            ->bind(Converters::builtIn(), [0 => $down]);

        self::assertSame($down, $chain->steps[0]->converter);
        self::assertInstanceOf(NumericStringToFloat::class, $chain->steps[1]->converter);
        self::assertSame(Direction::Down, $chain->direction);
    }

    public function test_a_missing_step_is_recorded_not_thrown(): void
    {
        $chain = ConversionChain::empty(Direction::Down)
            ->withStep(self::object(['a' => Schema::int()]), self::string(), 0, false, Converters::builtIn());

        self::assertCount(1, $chain->missing());
        self::assertSame(StepKind::Missing, $chain->missing()[0]->kind);
        self::assertSame('object>string:down', $chain->missing()[0]->key);
        self::assertSame(0, $chain->missing()[0]->change);
    }

    public function test_applying_a_missing_step_throws(): void
    {
        $chain = ConversionChain::empty(Direction::Down)
            ->withStep(self::object(['a' => Schema::int()]), self::string(), 0, false, Converters::builtIn())
            ->bind(Converters::builtIn(), []);

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('No conversion object>string:down');

        $chain->apply(['a' => 1]);
    }

    public function test_applying_an_unbound_chain_throws(): void
    {
        $chain = ConversionChain::empty(Direction::Down)->withStep(self::int(), self::string(), 0, false, Converters::builtIn());

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('No conversion int>string:down');

        $chain->apply(3);
    }

    public function test_an_explicit_step_without_its_converter_throws_when_applied(): void
    {
        $chain = ConversionChain::empty(Direction::Down)
            ->withStep(self::int(), self::string(), 0, true, Converters::builtIn())
            ->bind(Converters::builtIn(), [1 => self::down()]);

        $this->expectException(ConversionException::class);

        $chain->apply(3);
    }

    public function test_only_missing_steps_are_reported(): void
    {
        $chain = ConversionChain::empty(Direction::Down)
            ->withStep(self::int(), self::string(), 0, false, Converters::builtIn())
            ->withStep(self::string(), self::list(Schema::string()), 1, false, Converters::builtIn())
            ->withStep(self::list(Schema::string()), self::string(), 2, true, Converters::builtIn());

        self::assertSame(['string>list:down'], array_map(static fn($step) => $step->key, $chain->missing()));
    }

    public function test_an_empty_chain_keeps_the_value(): void
    {
        $chain = ConversionChain::empty(Direction::Up);

        self::assertTrue($chain->isEmpty());
        self::assertSame([], $chain->steps);
        self::assertSame('kept', $chain->apply('kept'));
        self::assertFalse($chain->withStep(self::int(), self::string(), 0, false, Converters::builtIn())->isEmpty());
    }

    public function test_null_is_returned_as_it_is(): void
    {
        $chain = ConversionChain::empty(Direction::Down)->withStep(self::int(), self::string(), 0, false, Converters::builtIn());

        self::assertNull($chain->apply(null));
    }

    public function test_the_subject_reaches_the_closures(): void
    {
        $chain = ConversionChain::empty(Direction::Down)
            ->withStep(self::int(), self::string(), 0, true, Converters::builtIn())
            ->bind(Converters::builtIn(), [0 => new ClosureConverter(static fn(int $cents, string $currency): string => "{$cents} {$currency}")]);

        self::assertSame('1250 EUR', $chain->apply(1250, 'EUR'));
    }

    public function test_an_explicit_converter_adds_a_step_between_identical_types(): void
    {
        $chain = ConversionChain::empty(Direction::Down)
            ->withStep(self::string(), self::string(), 0, true, Converters::builtIn())
            ->bind(Converters::builtIn(), [0 => new ClosureConverter(static fn(string $value): string => strtoupper($value))]);

        self::assertSame('PAID', $chain->apply('paid'));
    }

    /**
     * @return iterable<string, array{TypeDefinition, TypeDefinition}>
     */
    public static function sameValues(): iterable
    {
        yield 'a rename only' => [self::string(), self::string()];
        yield 'constraints' => [Schema::int()->min(1)->toType(), Schema::int()->max(9)->toType()];
        yield 'a string format' => [Schema::string()->format('email')->toType(), self::string()];
        yield 'a date format' => [Schema::date()->format('d/m/Y')->toType(), Schema::date()->format('Y-m-d')->toType()];
        yield 'documentation' => [Schema::int()->describe('cents')->example(3)->deprecated()->toType(), self::int()];
        yield 'the same enum' => [Schema::enum(OrderStatus::class)->toType(), Schema::enum(OrderStatus::class)->toType()];
        yield 'the same custom type' => [(new MoneySchema())->toType(), (new MoneySchema(currency: 'EUR'))->toType()];
        yield 'lists of the same items' => [self::list(Schema::int()->min(0)), self::list(Schema::int())];
        yield 'objects of the same children in another order' => [
            self::object(['a' => Schema::int(), 'b' => Schema::string()]),
            self::object(['b' => Schema::string()->max(3), 'a' => Schema::int()]),
        ];
        yield 'the same resource' => [Schema::resource(CustomerDeclaration::class)->toType(), Schema::resource(CustomerDeclaration::class, 'buyer')->toType()];
    }

    #[DataProvider('sameValues')]
    public function test_types_holding_the_same_values_add_no_step(TypeDefinition $from, TypeDefinition $to): void
    {
        self::assertTrue(ConversionChain::empty(Direction::Down)->withStep($from, $to, 0, false, Converters::builtIn())->isEmpty());
    }

    /**
     * @return iterable<string, array{TypeDefinition, TypeDefinition, StepKind}>
     */
    public static function otherValues(): iterable
    {
        yield 'another kind' => [self::int(), self::float(), StepKind::Registered];
        yield 'an enum to its backing' => [Schema::enum(OrderStatus::class)->toType(), self::string(), StepKind::Registered];
        yield 'another enum' => [Schema::enum(OrderStatus::class)->toType(), Schema::enum(Priority::class)->toType(), StepKind::Missing];
        yield 'another fragment of a custom type' => [(new MoneySchema())->toType(), TypeDefinition::custom('money', ['type' => 'string']), StepKind::Missing];
        yield 'another custom type' => [(new MoneySchema())->toType(), TypeDefinition::custom('price'), StepKind::Missing];
        yield 'lists of other items' => [self::list(Schema::int()), self::list(Schema::string()), StepKind::Missing];
        yield 'a list to items without type' => [self::list(Schema::int()), TypeDefinition::of(Kind::List), StepKind::Missing];
        yield 'objects of other children' => [self::object(['a' => Schema::int()]), self::object(['b' => Schema::int()]), StepKind::Missing];
        yield 'objects of children of another type' => [self::object(['a' => Schema::int()]), self::object(['a' => Schema::string()]), StepKind::Missing];
        yield 'another resource' => [Schema::resource(CustomerDeclaration::class)->toType(), Schema::resource(OrderDeclaration::class)->toType(), StepKind::Missing];
        yield 'a resource to an object' => [Schema::resource(CustomerDeclaration::class)->toType(), self::object([]), StepKind::Missing];
        yield 'objects of more children' => [self::object(['a' => Schema::int()]), self::object(['a' => Schema::int(), 'b' => Schema::int()]), StepKind::Missing];
    }

    #[DataProvider('otherValues')]
    public function test_types_holding_other_values_add_a_step(TypeDefinition $from, TypeDefinition $to, StepKind $kind): void
    {
        $chain = ConversionChain::empty(Direction::Down)->withStep($from, $to, 0, false, Converters::builtIn());

        self::assertCount(1, $chain->steps);
        self::assertSame($kind, $chain->steps[0]->kind);
        self::assertSame($from, $chain->steps[0]->from);
        self::assertSame($to, $chain->steps[0]->to);
    }

    public function test_a_bound_chain_serializes_as_its_planned_form(): void
    {
        $planned = ConversionChain::empty(Direction::Down)
            ->withStep(self::int(), self::string(), 0, true, Converters::builtIn())
            ->withStep(self::string(), self::float(), 1, false, Converters::builtIn());

        $restored = unserialize(serialize($planned->bind(Converters::builtIn(), [0 => self::down()])));

        self::assertEquals($planned, $restored);
    }

    public function test_step_of_gives_the_step_crossing_a_was(): void
    {
        $chain = ConversionChain::empty(Direction::Up)
            ->withStep(self::float(), self::string(), 2, false, Converters::builtIn())
            ->withStep(self::string(), self::string(), 1, false, Converters::builtIn())
            ->withStep(self::string(), self::int(), 0, true, Converters::builtIn());

        self::assertSame('float>string:up', $chain->stepOf(2)?->key);
        self::assertNull($chain->stepOf(1));
        self::assertSame('string>int:up', $chain->stepOf(0)?->key);
    }

    private static function int(): TypeDefinition
    {
        return Schema::int()->toType();
    }

    private static function string(): TypeDefinition
    {
        return Schema::string()->toType();
    }

    private static function float(): TypeDefinition
    {
        return Schema::float()->toType();
    }

    /**
     * @param Descriptor<mixed> $items
     */
    private static function list(Descriptor $items): TypeDefinition
    {
        return Schema::list($items)->toType();
    }

    /**
     * @param array<string, Descriptor<mixed>> $children
     */
    private static function object(array $children): TypeDefinition
    {
        return Schema::object($children)->toType();
    }

    private static function down(): ClosureConverter
    {
        return new ClosureConverter(static fn(int $cents): string => number_format($cents / 100, 2, '.', ''));
    }

    private static function up(): ClosureConverter
    {
        return new ClosureConverter(static fn(string $amount): int => (int) round(((float) $amount) * 100));
    }
}
