<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\IntSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class IntSchemaTest extends TestCase
{
    public function test_int_schema_describes_its_bounds(): void
    {
        $type = (new IntSchema())->min(0)->max(100)->toType();

        self::assertSame(Kind::Int, $type->kind);
        self::assertSame(0, $type->min);
        self::assertSame(100, $type->max);
    }

    public function test_int_schema_normalizes_an_integral_numeric_string(): void
    {
        $schema = new IntSchema();

        self::assertSame(1250, $schema->normalize('1250'));
        self::assertSame(-3, $schema->normalize('-3'));
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function accepted(): iterable
    {
        yield 'int' => [1250, 1250];
        yield 'integral float' => [12.0, 12];
        yield 'negative integral float' => [-4.0, -4];
        yield 'smallest int as a float' => [(float) PHP_INT_MIN, PHP_INT_MIN];
        yield 'leading zeros' => ['007', 7];
        yield 'negative leading zeros' => ['-007', -7];
        yield 'negative zero' => ['-0', 0];
        yield 'largest int' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'decimal string with an integral value' => ['12.00', 12];
        yield 'exponent with an integral value' => ['1e3', 1000];
        yield 'negative decimal string' => ['-4.0', -4];
        yield 'decimal string at 2^53' => ['9007199254740992.0', 9_007_199_254_740_992];
    }

    #[DataProvider('accepted')]
    public function test_int_schema_normalizes_ints_and_integral_floats(mixed $value, int $expected): void
    {
        self::assertSame($expected, (new IntSchema())->normalize($value));
    }

    public function test_bounds_return_a_new_descriptor(): void
    {
        $schema = new IntSchema();

        $schema->min(1);
        $schema->max(2);

        self::assertNull($schema->toType()->min);
        self::assertNull($schema->toType()->max);
    }

    public function test_int_schema_refuses_a_fractional_float(): void
    {
        self::assertRefused((new IntSchema())->normalize(12.5), 12.5, Kind::Int, 12);
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function refused(): iterable
    {
        yield 'not numeric' => ['abc', 0];
        yield 'fractional string' => ['12.5', 12];
        yield 'blank before' => [' 12', 12];
        yield 'trailing newline' => ["12\n", 12];
        yield 'blank after a decimal' => ['12.0 ', 12];
        yield 'integral but beyond exact floats' => ['1e20', PHP_INT_MAX];
        yield 'decimal string beyond 2^53' => ['9007199254740994.0', 9_007_199_254_740_994];
        yield 'beyond the int range' => ['99999999999999999999', PHP_INT_MAX];
        yield 'float beyond the int range' => [1e19, null];
        yield 'float at 2^63' => [(float) PHP_INT_MAX, null];
        yield 'float below the int range' => [-1e19, null];
        yield 'string below the int range' => ['-99999999999999999999', PHP_INT_MIN];
        yield 'infinite' => [INF, null];
        yield 'bool' => [true, 1];
        yield 'array' => [[1], null];
        yield 'null' => [null, null];
    }

    #[DataProvider('refused')]
    public function test_int_schema_refuses_other_values_with_their_cast(mixed $value, mixed $cast): void
    {
        self::assertRefused((new IntSchema())->normalize($value), $value, Kind::Int, $cast);
    }
}
