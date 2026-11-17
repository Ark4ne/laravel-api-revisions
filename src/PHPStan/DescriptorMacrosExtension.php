<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\PHPStan;

use Ark4ne\ApiRevisions\Schema\Descriptor;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;

/**
 * Declares on every descriptor the macros the application maps in its PHPStan configuration (`apiRevisions.macros`,
 * extension.neon): `->whenInFields(...)` takes the parameters of the mapped public static method after
 * the first (the descriptor) and returns the receiver's type. A name mapped to anything else (a missing class or
 * method, an instance or non-public method, one whose first parameter is missing, variadic or cannot take a
 * descriptor) stays an undefined method; so does an unmapped
 * one. Limit: the template types of the method are not resolved in its other parameters.
 */
final class DescriptorMacrosExtension implements MethodsClassReflectionExtension
{
    /**
     * @param array<string, string> $macros registered name => `Class::method`, as given to `DescriptorMacros::register()`
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly array $macros,
    ) {}

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->is(Descriptor::class) && $this->macro($methodName) !== null;
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        // PHPStan asks only after hasMethod() said yes.
        $macro = $this->macro($methodName) ?? throw new ShouldNotHappenException("{$methodName}() is not a mapped descriptor macro.");
        $return = new StaticType($classReflection);
        $variants = array_map(
            static fn(ParametersAcceptor $variant): FunctionVariant => new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                null,
                array_slice($variant->getParameters(), 1),
                $variant->isVariadic(),
                $return,
            ),
            $macro->getVariants(),
        );

        return new DeclaredMethod($classReflection, $methodName, $variants, static: false);
    }

    /**
     * The public static method with a first parameter mapped to `$name`, when it exists.
     */
    private function macro(string $name): ?ExtendedMethodReflection
    {
        $parts = explode('::', $this->macros[$name] ?? '', 2);
        if (count($parts) !== 2 || !$this->reflectionProvider->hasClass($parts[0])) {
            return null;
        }
        $class = $this->reflectionProvider->getClass($parts[0]);
        if (!$class->hasNativeMethod($parts[1])) {
            return null;
        }
        $method = $class->getNativeMethod($parts[1]);
        $first = $method->getVariants()[0]->getParameters()[0] ?? null;
        if (!$method->isPublic() || !$method->isStatic() || $first === null || $first->isVariadic()) {
            return null;
        }

        return $first->getType()->isSuperTypeOf(new ObjectType(Descriptor::class))->no() ? null : $method;
    }
}
