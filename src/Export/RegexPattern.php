<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Export;

/**
 * The ECMA `pattern` of a PCRE written with its delimiters, as `StringSchema::pattern()` takes it: the
 * body between its delimiters (bracket pairs included) when its flags are none, `u` or `D`; `null` otherwise.
 *
 * Public contract for tools (docs/architecture.md §4): the `pattern` `JsonSchemaExporter` writes for a
 * string pattern (`null`: a `$comment` `PCRE …` instead).
 */
final class RegexPattern
{
    private function __construct() {}

    private const array CLOSING = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    public static function of(string $pcre): ?string
    {
        if (strlen($pcre) < 2 || ctype_alnum($pcre[0]) || ctype_space($pcre[0]) || $pcre[0] === '\\') {
            return null;
        }
        $end = strrpos($pcre, self::CLOSING[$pcre[0]] ?? $pcre[0]);
        if ($end === false || $end === 0) {
            return null;
        }
        $flags = substr($pcre, $end + 1);

        return strspn($flags, 'uD') === strlen($flags) ? substr($pcre, 1, $end - 1) : null;
    }
}
