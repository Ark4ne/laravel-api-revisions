<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\EnumRules;
use Illuminate\Validation\Rules\Enum;
use Test\Fixtures\Enums\OrderStatus;
use Test\TestCase;

final class EnumRulesTest extends TestCase
{
    public function test_an_enum_gets_the_enum_rule_of_its_class(): void
    {
        $type = TypeDefinition::of(Kind::Enum)->withEnum(OrderStatus::class, Kind::String, ['pending', 'paid', 'shipped']);

        self::assertEquals([new Enum(OrderStatus::class)], (new EnumRules())->rules($type));
        self::assertSame([], (new EnumRules())->rules(TypeDefinition::of(Kind::Enum)));
    }
}
