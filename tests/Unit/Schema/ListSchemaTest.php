<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\DateSchema;
use Ark4ne\ApiRevisions\Schema\IntSchema;
use Ark4ne\ApiRevisions\Schema\ListSchema;
use Ark4ne\ApiRevisions\Schema\StringSchema;
use ArrayIterator;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\TestCase;

final class ListSchemaTest extends TestCase
{
    public function test_a_list_describes_its_items_and_size(): void
    {
        $type = (new ListSchema((new StringSchema())->max(20)))->min(1)->max(10)->toType();

        self::assertSame(Kind::List, $type->kind);
        self::assertSame(1, $type->min);
        self::assertSame(10, $type->max);
        self::assertSame(Kind::String, $type->items?->kind);
        self::assertSame(20, $type->items->max);
    }

    public function test_a_list_keeps_its_items_and_its_source(): void
    {
        $items = new IntSchema();

        $list = new ListSchema($items, 'quantities');

        self::assertSame($items, $list->items());
        self::assertSame(['quantities'], $list->source()->path);
    }

    /**
     * @return iterable<string, array{iterable<mixed>, list<mixed>}>
     */
    public static function iterables(): iterable
    {
        yield 'list' => [['a', 'b'], ['a', 'b']];
        yield 'keyed array' => [['x' => 'a', 'y' => 'b'], ['a', 'b']];
        yield 'empty' => [[], []];
        yield 'iterator' => [new ArrayIterator(['k' => 1, 'l' => 2]), [1, 2]];
        yield 'generator' => [(static function (): Generator {
            yield 'a' => 1;
            yield 'a' => 2;
        })(), [1, 2]];
    }

    /**
     * @param iterable<mixed> $value
     * @param list<mixed> $expected
     */
    #[DataProvider('iterables')]
    public function test_a_list_normalizes_any_iterable(iterable $value, array $expected): void
    {
        self::assertSame($expected, (new ListSchema(new StringSchema()))->normalize($value));
    }

    public function test_a_list_leaves_its_elements_to_its_items(): void
    {
        $element = new stdClass();

        self::assertSame([$element, 'raw'], (new ListSchema(new IntSchema()))->normalize([$element, 'raw']));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refused(): iterable
    {
        yield 'string' => ['a,b'];
        yield 'int' => [3];
        yield 'object' => [new stdClass()];
        yield 'null' => [null];
    }

    #[DataProvider('refused')]
    public function test_a_list_refuses_what_is_not_iterable(mixed $value): void
    {
        self::assertRefused((new ListSchema(new StringSchema()))->normalize($value), $value, Kind::List, null);
    }

    public function test_a_list_serializes_its_serialized_items_as_they_are(): void
    {
        self::assertSame(['a', 'b'], (new ListSchema(new StringSchema()))->serialize(['a', 'b']));
    }

    public function test_configure_reaches_the_items(): void
    {
        $list = (new ListSchema(new DateSchema()))->configure(new Configuration(dateFormat: 'd/m/Y'));

        self::assertSame('d/m/Y', $list->toType()->items?->format);
    }

    public function test_size_and_configure_return_new_descriptors(): void
    {
        $list = new ListSchema(new DateSchema());

        $list->min(1);
        $list->max(2);
        $list->configure(new Configuration(dateFormat: 'd/m/Y'));

        self::assertNull($list->toType()->min);
        self::assertNull($list->toType()->max);
        self::assertSame('Y-m-d', $list->toType()->items?->format);
    }
}
