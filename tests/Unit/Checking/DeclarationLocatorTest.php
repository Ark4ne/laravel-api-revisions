<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\DeclarationLocator;
use ArrayObject;
use ReflectionClass;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Declarations\PrunableDeclaration;
use Test\TestCase;

final class DeclarationLocatorTest extends TestCase
{
    public function test_a_field_is_located_at_its_key_the_match_naming_the_bound_preferred(): void
    {
        $locator = new DeclarationLocator();
        [$file, $source] = self::source(PrunableDeclaration::class);

        self::assertSame(['file' => $file, 'line' => self::line($source, "'name' => Schema::string(),")], $locator->locate(PrunableDeclaration::class, 'name'));
        self::assertSame(['file' => $file, 'line' => self::line($source, "'name' => Schema::string()->was(")], $locator->locate(PrunableDeclaration::class, 'name', ApiRevision::Genesis));
        self::assertSame(['file' => $file, 'line' => self::line($source, "'inner' =>")], $locator->locate(PrunableDeclaration::class, 'old.inner'));
    }

    public function test_a_key_is_searched_only_at_the_level_of_its_path(): void
    {
        $locator = new DeclarationLocator();
        [$file, $source] = self::source(PrunableDeclaration::class);
        $nested = self::line($source, "'code' => Schema::string()->since(ApiRevision::Genesis),\n                ]),");

        self::assertSame(['file' => $file, 'line' => $nested], $locator->locate(PrunableDeclaration::class, 'meta.code', ApiRevision::Genesis));
        self::assertSame(['file' => $file, 'line' => $nested + 2], $locator->locate(PrunableDeclaration::class, 'code', ApiRevision::Genesis));
    }

    public function test_an_unknown_key_falls_back_to_the_declaring_method(): void
    {
        [$file, $source] = self::source(PrunableDeclaration::class);

        self::assertSame(['file' => $file, 'line' => self::line($source, 'public static function describeRevisioned')], (new DeclarationLocator())->locate(PrunableDeclaration::class, 'missing'));
        self::assertSame(['file' => $file, 'line' => self::line($source, 'public static function describeRevisioned')], (new DeclarationLocator())->locate(PrunableDeclaration::class, 'address.missing'));
    }

    public function test_the_methods_are_searched_in_order_and_without_any_the_file_alone_is_known(): void
    {
        [$file, $source] = self::source(OrderDeclaration::class);

        self::assertSame(['file' => $file, 'line' => self::line($source, "'title' =>")], (new DeclarationLocator(['toArray', 'describeRevisioned']))->locate(OrderDeclaration::class, 'title'));
        self::assertSame(['file' => $file, 'line' => null], (new DeclarationLocator(['body']))->locate(OrderDeclaration::class, 'title'));
    }

    public function test_a_class_without_file_is_not_located(): void
    {
        self::assertSame(['file' => null, 'line' => null], (new DeclarationLocator())->locate(ArrayObject::class, 'title'));
    }

    /**
     * @param class-string $class
     *
     * @return array{string, string}
     */
    private static function source(string $class): array
    {
        $file = (string) (new ReflectionClass($class))->getFileName();

        return [$file, (string) file_get_contents($file)];
    }

    private static function line(string $source, string $needle): int
    {
        $offset = strpos($source, $needle);
        self::assertNotFalse($offset, $needle);

        return substr_count($source, "\n", 0, $offset) + 1;
    }
}
