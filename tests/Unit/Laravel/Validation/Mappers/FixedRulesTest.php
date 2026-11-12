<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\FixedRules;
use Test\TestCase;

final class FixedRulesTest extends TestCase
{
    public function test_fixed_rules_ignore_the_constraints(): void
    {
        self::assertSame(['boolean'], (new FixedRules(['boolean']))->rules(TypeDefinition::of(Kind::Bool)->withMin(1)));
        self::assertSame([], (new FixedRules())->rules(TypeDefinition::of(Kind::Any)));
    }
}
