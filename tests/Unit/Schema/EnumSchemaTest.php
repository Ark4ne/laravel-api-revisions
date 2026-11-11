<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\EnumSchema;
use BackedEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Enums\Priority;
use Test\Fixtures\Enums\Suit;
use Test\TestCase;

final class EnumSchemaTest extends TestCase
{
    public function test_enum_schema_exports_the_values_of_the_backed_enum(): void
    {
        $type = (new EnumSchema(OrderStatus::class))->toType();

        self::assertSame(Kind::Enum, $type->kind);
        self::assertSame(OrderStatus::class, $type->enum);
        self::assertSame(Kind::String, $type->backing);
        self::assertSame(['pending', 'paid', 'shipped'], $type->values);
    }

    public function test_an_int_backed_enum_exports_int_values(): void
    {
        $type = (new EnumSchema(Priority::class))->toType();

        self::assertSame(Kind::Int, $type->backing);
        self::assertSame([1, 3], $type->values);
    }

    public function test_enum_schema_normalizes_a_backed_value_to_its_case(): void
    {
        self::assertSame(OrderStatus::Paid, (new EnumSchema(OrderStatus::class))->normalize('paid'));
        self::assertSame(Priority::High, (new EnumSchema(Priority::class))->normalize(3));
    }

    public function test_enum_schema_keeps_a_case(): void
    {
        self::assertSame(OrderStatus::Shipped, (new EnumSchema(OrderStatus::class))->normalize(OrderStatus::Shipped));
    }

    public function test_an_int_backed_enum_accepts_what_int_schema_accepts(): void
    {
        self::assertSame(Priority::High, (new EnumSchema(Priority::class))->normalize('3'));
        self::assertSame(Priority::High, (new EnumSchema(Priority::class))->normalize(3.0));
    }

    public function test_a_string_backed_enum_refuses_an_int(): void
    {
        self::assertRefused((new EnumSchema(OrderStatus::class))->normalize(3), 3, Kind::Enum, 3);
    }

    /**
     * @return iterable<string, array{class-string<BackedEnum>, mixed, mixed}>
     */
    public static function refused(): iterable
    {
        yield 'unknown value' => [OrderStatus::class, 'cancelled', 'cancelled'];
        yield 'unknown int' => [Priority::class, 2, 2];
        yield 'not an integral string' => [Priority::class, 'high', 'high'];
        yield 'case of another enum' => [OrderStatus::class, Priority::High, null];
        yield 'fractional float' => [Priority::class, 3.5, null];
        yield 'integral float of no case' => [Priority::class, 2.0, 2];
        yield 'integral string of no case' => [Priority::class, '2', 2];
        yield 'array' => [OrderStatus::class, ['paid'], null];
        yield 'null' => [OrderStatus::class, null, null];
    }

    /**
     * @param class-string<BackedEnum> $enum
     */
    #[DataProvider('refused')]
    public function test_enum_schema_refuses_other_values_and_casts_to_the_raw_value(string $enum, mixed $value, mixed $cast): void
    {
        self::assertRefused((new EnumSchema($enum))->normalize($value), $value, Kind::Enum, $cast);
    }

    public function test_enum_schema_serializes_the_backed_value(): void
    {
        self::assertSame('paid', (new EnumSchema(OrderStatus::class))->serialize(OrderStatus::Paid));
        self::assertSame(3, (new EnumSchema(Priority::class))->serialize(Priority::High));
    }

    public function test_enum_schema_keeps_its_enum_and_its_source(): void
    {
        $schema = new EnumSchema(OrderStatus::class, 'state');

        self::assertSame(OrderStatus::class, $schema->enum());
        self::assertSame(['state'], $schema->source()->path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notBackedEnums(): iterable
    {
        yield 'pure enum' => [Suit::class];
        yield 'class' => [stdClass::class];
        yield 'unknown class' => ['Test\Fixtures\Enums\Missing'];
    }

    #[DataProvider('notBackedEnums')]
    public function test_a_class_that_is_not_a_backed_enum_is_refused(string $class): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Schema::enum() needs a backed enum, got {$class}.");

        new EnumSchema($class); // @phpstan-ignore argument.type
    }

    public function test_serialize_leaves_a_value_of_another_type_as_it_is(): void
    {
        self::assertSame('raw', (new EnumSchema(OrderStatus::class))->serialize('raw'));
    }
}
