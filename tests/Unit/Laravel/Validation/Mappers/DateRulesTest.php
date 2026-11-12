<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\DateRules;
use Test\TestCase;

final class DateRulesTest extends TestCase
{
    public function test_a_date_gets_its_format(): void
    {
        self::assertSame(['date_format:d/m/Y'], (new DateRules())->rules(TypeDefinition::of(Kind::Date)->withFormat('d/m/Y')));
        self::assertSame(['date'], (new DateRules())->rules(TypeDefinition::of(Kind::Date)));
    }
}
