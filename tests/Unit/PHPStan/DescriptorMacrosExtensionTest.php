<?php

declare(strict_types=1);

namespace Test\Unit\PHPStan;

use Ark4ne\ApiRevisions\PHPStan\DescriptorMacrosExtension;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Schema\StringSchema;
use PHPStan\ShouldNotHappenException;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\Macros\FieldMacros;
use Test\Fixtures\MoneySchema;

/**
 * The extension runs inside PHPStan, on the configuration of `extension.neon` here.
 */
final class DescriptorMacrosExtensionTest extends TypeInferenceTestCase
{
    /**
     * @return iterable<mixed>
     */
    public static function dataFileAsserts(): iterable
    {
        yield from self::gatherAssertTypes(__DIR__ . '/data/descriptor-macros.php');
    }

    #[DataProvider('dataFileAsserts')]
    public function test_a_mapped_macro_returns_the_type_of_its_receiver(string $assertType, string $file, mixed ...$args): void
    {
        $this->assertFileAsserts($assertType, $file, ...$args);
    }

    public function test_only_names_mapped_to_a_public_static_method_taking_a_descriptor_first_are_methods_of_descriptors(): void
    {
        $extension = self::extension();
        $provider = self::createReflectionProvider();
        $string = $provider->getClass(StringSchema::class);

        self::assertTrue($extension->hasMethod($string, 'whenFlag'));
        self::assertTrue($extension->hasMethod($provider->getClass(Descriptor::class), 'whenFlag'));
        self::assertTrue($extension->hasMethod($provider->getClass(MoneySchema::class), 'documented'));
        self::assertFalse($extension->hasMethod($provider->getClass(Schema::class), 'whenFlag'));
        foreach (['unmapped', 'noClass', 'noMethod', 'instance', 'noDescriptor', 'notAMethod', 'notADescriptor', 'variadic'] as $name) {
            self::assertFalse($extension->hasMethod($string, $name), $name);
        }
    }

    public function test_a_macro_takes_the_parameters_after_the_descriptor(): void
    {
        $method = self::extension()->getMethod(self::createReflectionProvider()->getClass(StringSchema::class), 'documented');
        $variant = $method->getVariants()[0];

        self::assertSame(['description', 'example'], array_map(static fn($parameter): string => $parameter->getName(), $variant->getParameters()));
        self::assertFalse($method->isStatic());
        self::assertSame('documented', $method->getName());
    }

    public function test_asking_for_an_unmapped_name_should_not_happen(): void
    {
        $this->expectException(ShouldNotHappenException::class);
        $this->expectExceptionMessage('unmapped() is not a mapped descriptor macro.');

        self::extension()->getMethod(self::createReflectionProvider()->getClass(StringSchema::class), 'unmapped');
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/extension.neon'];
    }

    private static function extension(): DescriptorMacrosExtension
    {
        return new DescriptorMacrosExtension(self::createReflectionProvider(), [
            'whenFlag' => FieldMacros::class . '::whenFlag',
            'documented' => FieldMacros::class . '::documented',
            'noClass' => 'Test\Fixtures\Missing::whenFlag',
            'noMethod' => FieldMacros::class . '::missing',
            'instance' => FieldMacros::class . '::instance',
            'noDescriptor' => FieldMacros::class . '::noDescriptor',
            'notAMethod' => FieldMacros::class,
            'notADescriptor' => FieldMacros::class . '::notADescriptor',
            'variadic' => FieldMacros::class . '::variadic',
        ]);
    }
}
