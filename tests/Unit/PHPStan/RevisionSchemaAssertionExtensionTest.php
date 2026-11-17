<?php

declare(strict_types=1);

namespace Test\Unit\PHPStan;

use Ark4ne\ApiRevisions\PHPStan\RevisionSchemaAssertionExtension;
use Illuminate\Testing\TestResponse;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\VerbosityLevel;

/**
 * The `TestResponse` macro of the test helpers, declared to PHPStan; the package's own analysis of
 * `tests/Feature/TestingHelpersTest.php` runs it too.
 */
final class RevisionSchemaAssertionExtensionTest extends PHPStanTestCase
{
    public function test_only_the_macro_on_a_test_response_is_declared(): void
    {
        $provider = self::createReflectionProvider();
        $extension = new RevisionSchemaAssertionExtension($provider);

        self::assertTrue($extension->hasMethod($provider->getClass(TestResponse::class), 'assertMatchesRevisionSchema'));
        self::assertFalse($extension->hasMethod($provider->getClass(TestResponse::class), 'assertJson'));
        self::assertFalse($extension->hasMethod($provider->getClass(self::class), 'assertMatchesRevisionSchema'));
    }

    public function test_the_macro_takes_the_class_and_an_optional_revision_and_returns_the_response(): void
    {
        $provider = self::createReflectionProvider();
        $method = (new RevisionSchemaAssertionExtension($provider))->getMethod($provider->getClass(TestResponse::class), 'assertMatchesRevisionSchema');

        self::assertFalse($method->isStatic());
        $variant = $method->getVariants()[0];
        self::assertSame(['class', 'revision'], array_map(static fn($parameter): string => $parameter->getName(), $variant->getParameters()));
        self::assertSame('class-string<Ark4ne\ApiRevisions\Schema\Revisioned>', $variant->getParameters()[0]->getType()->describe(VerbosityLevel::precise()));
        self::assertTrue($variant->getParameters()[1]->isOptional());
        self::assertSame('static(Illuminate\Testing\TestResponse<TResponse of Symfony\Component\HttpFoundation\Response (class Illuminate\Testing\TestResponse, argument)>)', $variant->getReturnType()->describe(VerbosityLevel::precise()));
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/extension.neon'];
    }
}
