<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\PHPStan;

use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;

/**
 * A public method an extension of the package declares: `Schema::<name>()` of a mapped custom type (static, its
 * constructor's variants returning the type's class), `Router::revisioned()` (a macro, with side effects).
 */
final class DeclaredMethod implements MethodReflection
{
    /**
     * @param list<FunctionVariant> $variants
     */
    public function __construct(
        private readonly ClassReflection $class,
        private readonly string $name,
        private readonly array $variants,
        private readonly bool $static = true,
        private readonly bool $sideEffects = false,
    ) {}

    public function getDeclaringClass(): ClassReflection
    {
        return $this->class;
    }

    public function isStatic(): bool
    {
        return $this->static;
    }

    public function isPrivate(): bool
    {
        return false;
    }

    public function isPublic(): bool
    {
        return true;
    }

    public function getDocComment(): ?string
    {
        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    public function getVariants(): array
    {
        return $this->variants;
    }

    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getDeprecatedDescription(): ?string
    {
        return null;
    }

    public function isFinal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getThrowType(): ?Type
    {
        return null;
    }

    public function hasSideEffects(): TrinaryLogic
    {
        return TrinaryLogic::createFromBoolean($this->sideEffects);
    }
}
