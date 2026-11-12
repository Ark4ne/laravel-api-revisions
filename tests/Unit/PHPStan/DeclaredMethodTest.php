<?php

declare(strict_types=1);

namespace Test\Unit\PHPStan;

use Ark4ne\ApiRevisions\PHPStan\CustomTypesExtension;
use Ark4ne\ApiRevisions\PHPStan\DeclaredMethod;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Routing\Router;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\TrinaryLogic;
use PHPStan\Type\VerbosityLevel;
use Test\Fixtures\MoneySchema;

/**
 * The methods the extensions declare: a mapped custom type on `Schema` (`CustomTypesExtension`), the route macro
 * (`RevisionedRouteExtension`).
 */
final class DeclaredMethodTest extends PHPStanTestCase
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

    public function test_a_method_may_be_an_instance_method_with_side_effects(): void
    {
        $router = self::createReflectionProvider()->getClass(Router::class);

        $method = new DeclaredMethod($router, 'revisioned', [], static: false, sideEffects: true);

        self::assertFalse($method->isStatic());
        self::assertTrue($method->hasSideEffects()->yes());
    }
}
