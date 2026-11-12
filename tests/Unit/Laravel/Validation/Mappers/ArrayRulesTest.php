<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\ArrayRules;
use Test\TestCase;

final class ArrayRulesTest extends TestCase
{
    public function test_a_composite_gets_its_rule_and_its_size(): void
    {
        self::assertSame(['list', 'min:1', 'max:3'], (new ArrayRules('list'))->rules(TypeDefinition::of(Kind::List)->withMin(1)->withMax(3)));
        self::assertSame(['array'], (new ArrayRules('array'))->rules(TypeDefinition::of(Kind::Object)));
    }
}
