<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\ResourceSchema;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\Fixtures\Customer;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\TestCase;

final class ResourceSchemaTest extends TestCase
{
    public function test_a_resource_references_its_class_without_copying_its_shape(): void
    {
        $type = (new ResourceSchema(CustomerDeclaration::class))->toType();

        self::assertSame(Kind::Object, $type->kind);
        self::assertSame(CustomerDeclaration::class, $type->resource);
        self::assertSame([], $type->children);
        self::assertNull($type->items);
    }

    public function test_a_resource_keeps_its_class_and_reads_its_key_by_default(): void
    {
        $resource = new ResourceSchema(CustomerDeclaration::class);

        self::assertSame(CustomerDeclaration::class, $resource->class());
        self::assertFalse($resource->source()->isParent());
        self::assertSame(['buyer'], (new ResourceSchema(CustomerDeclaration::class, 'buyer'))->source()->path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notRevisioned(): iterable
    {
        yield 'a plain class' => [stdClass::class];
        yield 'a subject' => [Customer::class];
        yield 'an unknown class' => ['Test\Fixtures\Unknown'];
    }

    #[DataProvider('notRevisioned')]
    public function test_a_non_revisioned_class_is_refused(string $class): void
    {
        $this->expectExceptionObject(DefinitionException::notRevisioned($class));

        // @phpstan-ignore argument.type (a class that is not Revisioned, refused at run time)
        new ResourceSchema($class);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function subjects(): iterable
    {
        yield 'array' => [['name' => 'Ada']];
        yield 'object' => [new Customer('Ada', 'Lovelace')];
        yield 'array access' => [new ArrayObject(['name' => 'Ada'])];
    }

    #[DataProvider('subjects')]
    public function test_a_resource_keeps_the_subject_of_its_class(mixed $subject): void
    {
        self::assertSame($subject, (new ResourceSchema(CustomerDeclaration::class))->normalize($subject));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refused(): iterable
    {
        yield 'string' => ['Ada'];
        yield 'int' => [3];
        yield 'null' => [null];
    }

    #[DataProvider('refused')]
    public function test_a_resource_refuses_a_scalar_subject(mixed $value): void
    {
        self::assertRefused((new ResourceSchema(CustomerDeclaration::class))->normalize($value), $value, Kind::Object, null);
    }

    public function test_a_resource_serializes_as_a_json_object_even_when_empty(): void
    {
        $resource = new ResourceSchema(CustomerDeclaration::class);

        self::assertSame(['name' => 'Ada'], $resource->serialize(['name' => 'Ada']));
        self::assertSame('{}', json_encode($resource->serialize([])));
    }

    public function test_configure_keeps_the_class(): void
    {
        $resource = (new ResourceSchema(CustomerDeclaration::class))->nullable()->configure(new Configuration(dateFormat: 'd/m/Y'));

        self::assertSame(CustomerDeclaration::class, $resource->class());
        self::assertTrue($resource->isNullable());
    }
}
