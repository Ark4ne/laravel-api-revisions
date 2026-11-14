<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Support\Memo;
use PhpToken;
use ReflectionClass;

/**
 * Where a field is declared, read from the source by reflection (model.md D73): the line of its key (`'key' =>`, a
 * nested path matched key after key) among the tokens of the declaring methods of the class written in its own file,
 * the match naming `$bound` (`::Case`) preferred; else the first line of the first such method; else the file alone.
 */
final readonly class DeclarationLocator
{
    /**
     * @param list<string> $methods the methods that may declare fields, searched in this order
     */
    public function __construct(private array $methods = ['describeRevisioned']) {}

    /**
     * @param class-string $class
     * @param string $path the head key path (`customer.name`, `lines.*.sku`)
     *
     * @return array{file: string|null, line: int|null}
     */
    public function locate(string $class, string $path, ?Revision $bound = null): array
    {
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();
        if ($file === false) {
            return ['file' => null, 'line' => null];
        }
        $ranges = [];
        foreach ($this->methods as $name) {
            if ($reflection->hasMethod($name)) {
                $method = $reflection->getMethod($name);
                // a method of a trait or a parent is written in another file: it declares nothing of this class
                if ($method->getFileName() === $file && $method->getStartLine() !== false && $method->getEndLine() !== false) {
                    $ranges[] = [$method->getStartLine(), $method->getEndLine()];
                }
            }
        }
        if ($ranges === []) {
            return ['file' => $file, 'line' => null];
        }
        $segments = array_values(array_filter(explode('.', $path), static fn(string $segment): bool => $segment !== '*'));
        /** @var list<PhpToken> $tokens */
        $tokens = Memo::remember("declaration-locator:{$file}", static fn(): array => array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($file)),
            static fn(PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        )));
        $matches = [];
        foreach ($ranges as [$start, $end]) {
            $first = null;
            $last = null;
            foreach ($tokens as $index => $token) {
                if ($token->line >= $start && $token->line <= $end) {
                    $first ??= $index;
                    $last = $index;
                }
            }
            if ($first !== null && $last !== null) {
                array_push($matches, ...self::matches($tokens, $segments, $first, $last + 1));
            }
        }
        foreach ($matches as [$key, $to]) {
            if ($bound !== null && self::names($tokens, $key, $to, $bound)) {
                return ['file' => $file, 'line' => $tokens[$key]->line];
            }
        }

        return ['file' => $file, 'line' => isset($matches[0]) ? $tokens[$matches[0][0]]->line : $ranges[0][0]];
    }

    /**
     * Each `'segment' =>` of `$segments` nested key after key in the tokens `[$from, $to)`, each searched at its own
     * level (the value of another key is skipped): the index of the last key and the end (excluded) of its expression.
     *
     * @param list<PhpToken> $tokens
     * @param list<string> $segments
     *
     * @return list<array{int, int}>
     */
    private static function matches(array $tokens, array $segments, int $from, int $to): array
    {
        if ($segments === []) {
            return [];
        }
        $segment = array_shift($segments);
        $matches = [];
        for ($index = $from; $index < $to - 1; $index++) {
            $token = $tokens[$index];
            if (!$token->is(T_CONSTANT_ENCAPSED_STRING) || !$tokens[$index + 1]->is(T_DOUBLE_ARROW)) {
                continue;
            }
            $end = self::end($tokens, $index + 2, $to);
            if (substr($token->text, 1, -1) === $segment) {
                if ($segments === []) {
                    $matches[] = [$index, $end];
                } else {
                    array_push($matches, ...self::matches($tokens, $segments, $index + 2, $end));
                }
            }
            // the keys inside a value belong to the next level: searched only under the key of the path
            $index = $end;
        }

        return $matches;
    }

    /**
     * The end (excluded) of the expression starting at `$from`: the first `,` or closing bracket at its own depth.
     *
     * @param list<PhpToken> $tokens
     */
    private static function end(array $tokens, int $from, int $to): int
    {
        $depth = 0;
        for ($index = $from; $index < $to; $index++) {
            $text = $tokens[$index]->text;
            if (in_array($text, ['(', '[', '{'], true) || $tokens[$index]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return $index;
                }
                $depth--;
            } elseif ($depth === 0 && ($text === ',' || $text === ';')) {
                return $index;
            }
        }

        return $to;
    }

    /**
     * Whether the expression `[$from, $to)` names the case of `$bound` (`::Case`).
     *
     * @param list<PhpToken> $tokens
     */
    private static function names(array $tokens, int $from, int $to, Revision $bound): bool
    {
        for ($index = $from; $index < $to - 1; $index++) {
            if ($tokens[$index]->is(T_DOUBLE_COLON) && $tokens[$index + 1]->text === $bound->name) {
                return true;
            }
        }

        return false;
    }
}
