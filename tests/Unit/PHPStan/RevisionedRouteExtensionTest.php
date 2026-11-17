<?php

declare(strict_types=1);

namespace Test\Unit\PHPStan;

use Ark4ne\ApiRevisions\PHPStan\RevisionedRouteExtension;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\VerbosityLevel;

/**
 * The route macro of path mode, declared to PHPStan on the router and its facade; the package's own analysis
 * of `tests/Feature/PathModeTest.php` (`$router->revisioned()`) runs it too.
 */
final class RevisionedRouteExtensionTest extends PHPStanTestCase
{
    public function test_only_revisioned_on_the_router_and_its_facade_is_declared(): void
    {
        $extension = new RevisionedRouteExtension(self::createReflectionProvider());
        $provider = self::createReflectionProvider();

        self::assertTrue($extension->hasMethod($provider->getClass(Router::class), 'revisioned'));
        self::assertTrue($extension->hasMethod($provider->getClass(Route::class), 'revisioned'));
        self::assertFalse($extension->hasMethod($provider->getClass(Router::class), 'versioned'));
        self::assertFalse($extension->hasMethod($provider->getClass(self::class), 'revisioned'));
    }

    public function test_the_macro_takes_the_routes_closure_and_returns_nothing(): void
    {
        $provider = self::createReflectionProvider();
        $extension = new RevisionedRouteExtension($provider);

        $method = $extension->getMethod($provider->getClass(Router::class), 'revisioned');
        $static = $extension->getMethod($provider->getClass(Route::class), 'revisioned');

        self::assertFalse($method->isStatic());
        self::assertTrue($static->isStatic());
        self::assertCount(1, $method->getVariants());
        $variant = $method->getVariants()[0];
        self::assertFalse($variant->isVariadic());
        self::assertSame(['routes'], array_map(static fn($parameter): string => $parameter->getName(), $variant->getParameters()));
        self::assertSame('Closure(Illuminate\Routing\Router): void', $variant->getParameters()[0]->getType()->describe(VerbosityLevel::precise()));
        self::assertSame('void', $variant->getReturnType()->describe(VerbosityLevel::precise()));
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/extension.neon'];
    }
}
