<?php

declare(strict_types=1);

namespace Test\Unit\PHPStan;

use Ark4ne\ApiRevisions\PHPStan\CustomTypesExtension;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Schema\StringSchema;
use PHPStan\ShouldNotHappenException;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\MoneySchema;

/**
 * The extension runs inside PHPStan, on the configuration of `extension.neon` here.
 */
final class CustomTypesExtensionTest extends TypeInferenceTestCase
{
    /**
     * @return iterable<mixed>
     */
    public static function dataFileAsserts(): iterable
    {
        yield from self::gatherAssertTypes(__DIR__ . '/data/custom-types.php');
    }

    #[DataProvider('dataFileAsserts')]
    public function test_a_mapped_custom_type_returns_its_class(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    public function test_only_mapped_names_of_existing_descriptors_are_methods_of_schema(): void
    {
        $extension = self::extension();
        $schema = self::createReflectionProvider()->getClass(Schema::class);
        $other = self::createReflectionProvider()->getClass(StringSchema::class);

        self::assertTrue($extension->hasMethod($schema, 'money'));
        self::assertFalse($extension->hasMethod($schema, 'percent'));
        self::assertFalse($extension->hasMethod($schema, 'missing'));
        self::assertFalse($extension->hasMethod($schema, 'notADescriptor'));
        self::assertFalse($extension->hasMethod($schema, 'abstract'));
        self::assertFalse($extension->hasMethod($other, 'money'));
    }

    public function test_an_unmapped_name_has_no_method(): void
    {
        $this->expectException(ShouldNotHappenException::class);
        $this->expectExceptionMessage('Schema::percent() is not a mapped custom type.');

        self::extension()->getMethod(self::createReflectionProvider()->getClass(Schema::class), 'percent');
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/extension.neon'];
    }

    private static function extension(): CustomTypesExtension
    {
        return new CustomTypesExtension(self::createReflectionProvider(), [
            'money' => MoneySchema::class,
            'missing' => 'Test\Fixtures\Missing',
            'notADescriptor' => TypeInferenceTestCase::class,
            'abstract' => Descriptor::class,
        ]);
    }
}
