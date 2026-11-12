<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\NumberRules;
use Test\TestCase;

final class NumberRulesTest extends TestCase
{
    public function test_a_number_gets_its_rule_and_its_bounds(): void
    {
        self::assertSame(['integer', 'min:0', 'max:9'], (new NumberRules('integer'))->rules(TypeDefinition::of(Kind::Int)->withMin(0)->withMax(9)));
        self::assertSame(['numeric', 'min:0.5'], (new NumberRules('numeric'))->rules(TypeDefinition::of(Kind::Float)->withMin(0.5)));
        self::assertSame(['numeric'], (new NumberRules('numeric'))->rules(TypeDefinition::of(Kind::Float)));
    }
}
