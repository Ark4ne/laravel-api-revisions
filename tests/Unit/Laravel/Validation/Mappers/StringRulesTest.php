<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\StringRules;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class StringRulesTest extends TestCase
{
    public function test_a_string_gets_its_length_and_its_pattern(): void
    {
        $type = TypeDefinition::of(Kind::String)->withMin(2)->withMax(10)->withPattern('/^[a-z]+$/');

        self::assertSame(['string', 'min:2', 'max:10', 'regex:/^[a-z]+$/'], (new StringRules())->rules($type));
        self::assertSame(['string'], (new StringRules())->rules(TypeDefinition::of(Kind::String)));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function formats(): iterable
    {
        yield 'email' => ['email', ['string', 'email']];
        yield 'uri' => ['uri', ['string', 'url']];
        yield 'uuid' => ['uuid', ['string', 'uuid']];
        yield 'ipv4' => ['ipv4', ['string', 'ipv4']];
        yield 'ipv6' => ['ipv6', ['string', 'ipv6']];
        yield 'date' => ['date', ['string', 'date_format:Y-m-d']];
        yield 'date-time' => ['date-time', ['string', 'date']];
        yield 'unknown' => ['hostname', ['string']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('formats')]
    public function test_a_known_format_gets_its_rule(string $format, array $expected): void
    {
        self::assertSame($expected, (new StringRules())->rules(TypeDefinition::of(Kind::String)->withFormat($format)));
    }
}
