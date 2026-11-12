<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\PHPStan;

use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Schema;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\ObjectType;

/**
 * Declares on `Schema` the static methods of the custom types the application maps in its PHPStan configuration
 * (`apiRevisions.types`, extension.neon): `Schema::money(...)` takes the constructor's parameters of its class and
 * returns that class. An unmapped name stays an undefined method (US 2.7). Limit: a generic descriptor is returned
 * as its raw class (its template types are not resolved).
 */
final class CustomTypesExtension implements MethodsClassReflectionExtension
{
    /**
     * @param array<string, string> $types registered name => class, as given to `TypeRegistry::register()`
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly array $types,
    ) {}

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->getName() === Schema::class && $this->type($methodName) !== null;
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        // PHPStan asks only after hasMethod() said yes.
        $type = $this->type($methodName) ?? throw new ShouldNotHappenException("Schema::{$methodName}() is not a mapped custom type.");
        $return = new ObjectType($type->getName());
        $variants = array_map(
            static fn(ParametersAcceptor $constructor): FunctionVariant => new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                null,
                $constructor->getParameters(),
                $constructor->isVariadic(),
                $return,
            ),
            $type->getConstructor()->getVariants(),
        );

        return new DeclaredMethod($classReflection, $methodName, $variants);
    }

    /**
     * The class mapped to `$name` when it exists and is a concrete descriptor.
     */
    private function type(string $name): ?ClassReflection
    {
        $class = $this->types[$name] ?? null;
        if ($class === null || !$this->reflectionProvider->hasClass($class)) {
            return null;
        }
        $type = $this->reflectionProvider->getClass($class);

        return $type->isSubclassOf(Descriptor::class) && !$type->isAbstract() ? $type : null;
    }
}
