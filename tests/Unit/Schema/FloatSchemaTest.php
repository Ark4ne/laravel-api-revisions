<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\FloatSchema;
use Ark4ne\ApiRevisions\Schema\InvalidValue;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class FloatSchemaTest extends TestCase
{
    public function test_float_schema_describes_its_bounds(): void
    {
        $type = (new FloatSchema())->min(0)->max(99.5)->toType();

        self::assertSame(Kind::Float, $type->kind);
        self::assertSame(0, $type->min);
        self::assertSame(99.5, $type->max);
    }

    /**
     * @return iterable<string, array{mixed, float}>
     */
    public static function accepted(): iterable
    {
        yield 'float' => [12.5, 12.5];
        yield 'int' => [12, 12.0];
        yield 'numeric string' => ['12.5', 12.5];
        yield 'integral numeric string' => ['-3', -3.0];
        yield 'exponent' => ['1e3', 1000.0];
    }

    #[DataProvider('accepted')]
    public function test_float_schema_normalizes_numbers_and_numeric_strings(mixed $value, float $expected): void
    {
        self::assertSame($expected, (new FloatSchema())->normalize($value));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function refused(): iterable
    {
        yield 'not numeric' => ['abc', 0.0];
        yield 'blank around' => [' 12.5 ', 12.5];
        yield 'trailing newline' => ["12.5\n", 12.5];
        yield 'infinite' => [INF, null];
        yield 'infinite string' => ['1e999', null];
        yield 'bool' => [false, 0.0];
        yield 'array' => [[1.5], null];
        yield 'null' => [null, null];
    }

    #[DataProvider('refused')]
    public function test_float_schema_refuses_other_values_with_their_cast(mixed $value, mixed $cast): void
    {
        self::assertRefused((new FloatSchema())->normalize($value), $value, Kind::Float, $cast);
    }

    public function test_bounds_return_a_new_descriptor(): void
    {
        $schema = new FloatSchema();

        $schema->min(1);
        $schema->max(2);

        self::assertNull($schema->toType()->min);
        self::assertNull($schema->toType()->max);
    }

    public function test_nan_is_refused(): void
    {
        $refused = (new FloatSchema())->normalize(NAN);

        self::assertInstanceOf(InvalidValue::class, $refused);
        self::assertNull($refused->cast);
    }
}
