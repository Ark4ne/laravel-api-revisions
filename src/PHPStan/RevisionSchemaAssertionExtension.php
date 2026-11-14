<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\PHPStan;

use Ark4ne\ApiRevisions\Testing\TestResponseMacros;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\StaticType;

/**
 * Declares the `TestResponse` macro of the test helpers (US 10.4, model.md D72):
 * `$response->assertMatchesRevisionSchema($class, $revision = null)`, its parameters read from
 * `Testing\TestResponseMacros::assert()`, returning the response.
 */
final class RevisionSchemaAssertionExtension implements MethodsClassReflectionExtension
{
    private const string RESPONSE = 'Illuminate\Testing\TestResponse';

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $methodName === 'assertMatchesRevisionSchema' && $classReflection->is(self::RESPONSE);
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        $assert = $this->reflectionProvider->getClass(TestResponseMacros::class)->getNativeMethod('assert')->getVariants()[0];
        [, , $class, $revision] = $assert->getParameters();

        return new DeclaredMethod(
            $classReflection,
            $methodName,
            [new FunctionVariant(TemplateTypeMap::createEmpty(), null, [$class, $revision], false, new StaticType($classReflection))],
            static: false,
            sideEffects: true,
        );
    }
}
