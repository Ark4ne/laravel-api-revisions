<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\EagerSchema;
use Ark4ne\ApiRevisions\Schema\InvalidValue;
use Ark4ne\ApiRevisions\Support\Invoke;
use stdClass;
use Test\TestCase;

final class EagerSchemaTest extends TestCase
{
    public function test_an_eager_value_is_a_nullable_any_whose_source_gives_the_value(): void
    {
        $value = new stdClass();

        $schema = EagerSchema::of($value);

        self::assertSame($value, $schema->value());
        self::assertTrue($schema->isNullable());
        self::assertTrue($schema->isConditional());
        self::assertSame(Kind::Any, $schema->toType()->kind);
        self::assertNotNull($schema->source()->closure);
        self::assertSame($value, Invoke::value($schema->source()->closure, null, 'subject'));
    }

    public function test_an_eager_value_normalizes_and_serializes_any_value_as_is(): void
    {
        $schema = EagerSchema::of(null);

        self::assertSame(['a' => 1], $schema->normalize(['a' => 1]));
        self::assertSame('x', $schema->serialize('x'));
        self::assertInstanceOf(InvalidValue::class, $schema->normalize(null));
    }
}
