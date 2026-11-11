<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\BoolSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class BoolSchemaTest extends TestCase
{
    public function test_bool_schema_is_of_kind_bool(): void
    {
        self::assertSame(Kind::Bool, (new BoolSchema())->toType()->kind);
    }

    public function test_bool_schema_accepts_zero_and_one(): void
    {
        $schema = new BoolSchema();

        self::assertFalse($schema->normalize(0));
        self::assertTrue($schema->normalize(1));
        self::assertFalse($schema->normalize('0'));
        self::assertTrue($schema->normalize('1'));
        self::assertTrue($schema->normalize(true));
        self::assertFalse($schema->normalize(false));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function refused(): iterable
    {
        yield 'other int' => [2, true];
        yield 'word' => ['yes', true];
        yield 'empty string' => ['', false];
        yield 'float' => [1.0, true];
        yield 'array' => [[], null];
        yield 'null' => [null, null];
    }

    #[DataProvider('refused')]
    public function test_bool_schema_refuses_other_values_with_their_cast(mixed $value, mixed $cast): void
    {
        self::assertRefused((new BoolSchema())->normalize($value), $value, Kind::Bool, $cast);
    }
}
