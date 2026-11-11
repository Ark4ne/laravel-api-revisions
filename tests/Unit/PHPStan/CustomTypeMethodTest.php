<?php

declare(strict_types=1);

namespace Test\Unit\PHPStan;

use Ark4ne\ApiRevisions\PHPStan\CustomTypesExtension;
use Ark4ne\ApiRevisions\Schema\Schema;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\TrinaryLogic;
use PHPStan\Type\VerbosityLevel;
use Test\Fixtures\MoneySchema;

/**
 * The method a mapped custom type adds to `Schema`, as built by `CustomTypesExtension`.
 */
final class CustomTypeMethodTest extends PHPStanTestCase
{
    public function test_the_method_is_public_static_and_takes_the_constructor_parameters(): void
    {
        $schema = self::createReflectionProvider()->getClass(Schema::class);

        $method = (new CustomTypesExtension(self::createReflectionProvider(), ['money' => MoneySchema::class]))->getMethod($schema, 'money');

        self::assertSame('money', $method->getName());
        self::assertSame($schema, $method->getDeclaringClass());
        self::assertSame($method, $method->getPrototype());
        self::assertTrue($method->isStatic());
        self::assertTrue($method->isPublic());
        self::assertFalse($method->isPrivate());
        self::assertNull($method->getDocComment());
        self::assertNull($method->getDeprecatedDescription());
        self::assertNull($method->getThrowType());
        foreach ([$method->isDeprecated(), $method->isFinal(), $method->isInternal(), $method->hasSideEffects()] as $logic) {
            self::assertTrue($logic->equals(TrinaryLogic::createNo()));
        }
        $variant = $method->getVariants()[0];
        self::assertSame(['source', 'currency'], array_map(static fn($parameter): string => $parameter->getName(), $variant->getParameters()));
        self::assertSame(MoneySchema::class, $variant->getReturnType()->describe(VerbosityLevel::typeOnly()));
    }
}
