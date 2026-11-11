<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\InvalidValue;
use Ark4ne\ApiRevisions\Schema\StringSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Stringable;
use Test\TestCase;

final class StringSchemaTest extends TestCase
{
    public function test_string_schema_describes_its_constraints(): void
    {
        $type = (new StringSchema())->min(3)->max(40)->format('email')->pattern('/@acme\.test$/')->toType();

        self::assertSame(Kind::String, $type->kind);
        self::assertSame(3, $type->min);
        self::assertSame(40, $type->max);
        self::assertSame('email', $type->format);
        self::assertSame('/@acme\.test$/', $type->pattern);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function accepted(): iterable
    {
        yield 'string' => ['A-1', 'A-1'];
        yield 'int' => [42, '42'];
        yield 'float' => [12.5, '12.5'];
        yield 'integral float' => [12.0, '12'];
        yield 'float without precision loss' => [0.1 + 0.2, '0.30000000000000004'];
        yield 'short float stays short' => [0.1, '0.1'];
        yield 'large float' => [1e20, '1.0E+20'];
        yield 'negative float' => [-2.5, '-2.5'];
        yield 'stringable' => [new class implements Stringable {
            public function __toString(): string
            {
                return 'from object';
            }
        }, 'from object'];
    }

    #[DataProvider('accepted')]
    public function test_string_schema_normalizes_what_it_accepts(mixed $value, string $expected): void
    {
        self::assertSame($expected, (new StringSchema())->normalize($value));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function refused(): iterable
    {
        yield 'bool' => [true, '1'];
        yield 'infinite' => [INF, null];
        yield 'array' => [['a'], null];
        yield 'object' => [new stdClass(), null];
        yield 'null' => [null, null];
    }

    public function test_a_float_is_written_whatever_the_precision_setting(): void
    {
        $precision = ini_get('precision');
        ini_set('precision', '3');

        try {
            self::assertSame('0.30000000000000004', (new StringSchema())->normalize(0.1 + 0.2));
        } finally {
            ini_set('precision', $precision);
        }
    }

    public function test_nan_is_refused(): void
    {
        $refused = (new StringSchema())->normalize(NAN);

        self::assertInstanceOf(InvalidValue::class, $refused);
        self::assertNull($refused->cast);
    }

    #[DataProvider('refused')]
    public function test_string_schema_refuses_other_values_with_their_cast(mixed $value, mixed $cast): void
    {
        self::assertRefused((new StringSchema())->normalize($value), $value, Kind::String, $cast);
    }
}
