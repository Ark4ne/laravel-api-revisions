<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\DateSchema;
use Ark4ne\ApiRevisions\Schema\IntSchema;
use Ark4ne\ApiRevisions\Schema\ListSchema;
use Ark4ne\ApiRevisions\Schema\ObjectSchema;
use Ark4ne\ApiRevisions\Schema\StringSchema;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\TestCase;

final class ObjectSchemaTest extends TestCase
{
    public function test_an_object_describes_its_children(): void
    {
        $type = (new ObjectSchema([
            'street' => new StringSchema(),
            'zip' => (new StringSchema('postcode'))->max(10),
            'lines' => new ListSchema(new ObjectSchema(['quantity' => new IntSchema()])),
        ], 'shippingAddress'))->toType();

        self::assertSame(Kind::Object, $type->kind);
        self::assertSame(['street', 'zip', 'lines'], array_keys($type->children));
        self::assertSame(Kind::String, $type->children['street']->kind);
        self::assertSame(10, $type->children['zip']->max);
        self::assertSame(Kind::Int, $type->children['lines']->items?->children['quantity']->kind);
    }

    public function test_an_object_keeps_its_children_and_its_source(): void
    {
        $street = new StringSchema();

        $object = new ObjectSchema(['street' => $street], 'shippingAddress');

        self::assertSame(['street' => $street], $object->children());
        self::assertSame(['shippingAddress'], $object->source()->path);
        self::assertTrue((new ObjectSchema(['street' => $street]))->source()->isParent());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function subjects(): iterable
    {
        yield 'array' => [['street' => 'Main street']];
        yield 'empty array' => [[]];
        yield 'object' => [new stdClass()];
        yield 'array access' => [new ArrayObject(['street' => 'Main street'])];
    }

    #[DataProvider('subjects')]
    public function test_an_object_keeps_the_subject_of_its_children(mixed $subject): void
    {
        self::assertSame($subject, (new ObjectSchema(['street' => new StringSchema()]))->normalize($subject));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refused(): iterable
    {
        yield 'string' => ['Main street'];
        yield 'int' => [3];
        yield 'null' => [null];
    }

    #[DataProvider('refused')]
    public function test_an_object_refuses_a_scalar_subject(mixed $value): void
    {
        self::assertRefused((new ObjectSchema(['street' => new StringSchema()]))->normalize($value), $value, Kind::Object, null);
    }

    public function test_an_object_serializes_as_a_json_object_even_when_empty(): void
    {
        $object = new ObjectSchema(['street' => new StringSchema()]);

        self::assertSame(['street' => 'Main street'], $object->serialize(['street' => 'Main street']));
        self::assertSame('{}', json_encode($object->serialize([])));
    }

    public function test_configure_reaches_the_children_recursively(): void
    {
        $object = (new ObjectSchema([
            'placed_on' => new DateSchema(),
            'nested' => new ObjectSchema(['due_on' => new DateSchema()]),
        ]))->configure(new Configuration(dateFormat: 'd/m/Y'));

        self::assertSame('d/m/Y', $object->toType()->children['placed_on']->format);
        self::assertSame('d/m/Y', $object->toType()->children['nested']->children['due_on']->format);
    }

    public function test_configure_returns_a_new_descriptor(): void
    {
        $object = new ObjectSchema(['placed_on' => new DateSchema()]);

        $object->configure(new Configuration(dateFormat: 'd/m/Y'));

        self::assertSame('Y-m-d', $object->toType()->children['placed_on']->format);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidChildren(): iterable
    {
        yield 'list of descriptors' => [[new StringSchema()], 'Invalid child key 0 of Schema::object(): children are keyed by non-empty strings.'];
        yield 'empty key' => [['' => new StringSchema()], "Invalid child key '' of Schema::object(): children are keyed by non-empty strings."];
        yield 'not a descriptor' => [['street' => 'Main street'], "Invalid child 'street' of Schema::object(): a descriptor is expected, got string."];
    }

    /**
     * @param array<mixed> $children
     */
    #[DataProvider('invalidChildren')]
    public function test_a_child_that_is_not_a_keyed_descriptor_is_refused(array $children, string $message): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage($message);

        new ObjectSchema($children); // @phpstan-ignore argument.type
    }
}
