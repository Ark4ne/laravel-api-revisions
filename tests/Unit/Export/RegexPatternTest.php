<?php

declare(strict_types=1);

namespace Test\Unit\Export;

use Ark4ne\ApiRevisions\Export\RegexPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class RegexPatternTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function patterns(): iterable
    {
        yield 'slashes' => ['/^[a-z]+$/', '^[a-z]+$'];
        yield 'another delimiter' => ['#^a/b$#', '^a/b$'];
        yield 'bracket delimiters' => ['{^\d{3}$}', '^\d{3}$'];
        yield 'parenthesis delimiters' => ['(^a$)', '^a$'];
        yield 'the u flag' => ['/^é$/u', '^é$'];
        yield 'the u and D flags' => ['/^a$/uD', '^a$'];
        yield 'an empty body' => ['//', ''];
        yield 'the i flag' => ['/^a$/i', null];
        yield 'the x flag after u' => ['/^a$/ux', null];
        yield 'an alphanumeric delimiter' => ['a^ba', null];
        yield 'a backslash delimiter' => ['\\a\\', null];
        yield 'a space delimiter' => [' a ', null];
        yield 'no closing delimiter' => ['/abc', null];
        yield 'no closing bracket' => ['{abc', null];
        yield 'too short' => ['/', null];
    }

    #[DataProvider('patterns')]
    public function test_a_pcre_gives_its_body_when_its_flags_allow_it(string $pcre, ?string $expected): void
    {
        self::assertSame($expected, RegexPattern::of($pcre));
    }
}
