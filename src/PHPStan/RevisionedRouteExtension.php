<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\PHPStan;

use Ark4ne\ApiRevisions\Laravel\Routing\RevisionedRoutes;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\VoidType;

/**
 * Declares the route macro of path mode: `$router->revisioned($routes)` on the `Router` and
 * `Route::revisioned($routes)` on its facade, `$routes` typed as the last parameter of `RevisionedRoutes::group()`.
 */
final class RevisionedRouteExtension implements MethodsClassReflectionExtension
{
    private const string ROUTER = 'Illuminate\Routing\Router';

    private const string FACADE = 'Illuminate\Support\Facades\Route';

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $methodName === 'revisioned'
            && ($classReflection->getName() === self::FACADE || $classReflection->is(self::ROUTER));
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        $group = $this->reflectionProvider->getClass(RevisionedRoutes::class)->getNativeMethod('group')->getVariants()[0];
        $routes = $group->getParameters()[2];

        return new DeclaredMethod(
            $classReflection,
            $methodName,
            [new FunctionVariant(TemplateTypeMap::createEmpty(), null, [$routes], false, new VoidType())],
            static: $classReflection->getName() === self::FACADE,
            sideEffects: true,
        );
    }
}
