<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Export;

/**
 * The regular expression of a PHP date format whose tokens are all numeric (model.md D67): `d/m/Y` →
 * `^\d{2}/\d{2}/\d{4}$`; `null` as soon as one token is not (`D`, `M`, `l`, `F`, `U`, `e`, `T`…).
 *
 * Public contract for tools (docs/architecture.md §4, model.md D92): the `pattern` `JsonSchemaExporter` writes for a
 * date format JSON Schema does not name (a date other than `Y-m-d`, a date-time other than RFC 3339 and RFC 3339
 * extended; those, and no format, export `format`).
 */
final class DatePattern
{
    private function __construct() {}

    /**
     * The numeric tokens of a PHP date format and their expression.
     */
    private const array TOKENS = [
        'd' => '\\d{2}',
        'j' => '\\d{1,2}',
        'm' => '\\d{2}',
        'n' => '\\d{1,2}',
        'Y' => '\\d{4}',
        'y' => '\\d{2}',
        'H' => '\\d{2}',
        'G' => '\\d{1,2}',
        'i' => '\\d{2}',
        's' => '\\d{2}',
        'v' => '\\d{3}',
        'u' => '\\d{6}',
        'P' => '[+-]\\d{2}:\\d{2}',
        'O' => '[+-]\\d{4}',
    ];

    public static function of(string $format): ?string
    {
        $pattern = '';
        $length = strlen($format);
        for ($i = 0; $i < $length; ++$i) {
            $char = $format[$i];
            if ($char === '\\') {
                // an escaped character is a literal; a trailing backslash is not a format
                if (++$i === $length) {
                    return null;
                }
                $pattern .= self::literal($format[$i]);
            } elseif (isset(self::TOKENS[$char])) {
                $pattern .= self::TOKENS[$char];
            } elseif (ctype_alpha($char)) {
                return null;
            } else {
                $pattern .= self::literal($char);
            }
        }

        return '^' . $pattern . '$';
    }

    private static function literal(string $char): string
    {
        return str_contains('.\\+*?[^]$(){}|', $char) ? '\\' . $char : $char;
    }
}
