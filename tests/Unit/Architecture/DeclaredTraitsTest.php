<?php

declare(strict_types=1);

namespace Test\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Test\TestCase;

/**
 * Traits are declared for applications only, and listed in docs/architecture.md §16. phpat cannot enforce it: PHPStan
 * analyses a trait in the context of the classes using it, never as a declaration of its own.
 */
final class DeclaredTraitsTest extends TestCase
{
    /**
     * @var list<string>
     */
    public const ALLOWED = [
        'Ark4ne\ApiRevisions\Laravel\Requests\RevisionedRequest',
        'Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource',
        'Ark4ne\ApiRevisions\Revisions\IsRevision',
    ];

    public function test_only_the_listed_traits_are_declared_in_the_package(): void
    {
        $declared = self::traitsDeclaredIn(dirname(__DIR__, 3) . '/src');

        self::assertSame([], array_values(array_diff($declared, self::ALLOWED)), 'Traits not listed in docs/architecture.md §16.');
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function declarations(): iterable
    {
        yield 'file namespace' => ["<?php\nnamespace Acme\\Some;\nfinal class NotATrait {}\ntrait Helps {}\n", ['Acme\Some\Helps']];
        yield 'braced namespaces' => ["<?php\nnamespace A { trait One {} }\nnamespace B\\C { trait Two {} }\n", ['A\One', 'B\C\Two']];
        yield 'global braced namespace' => ["<?php\nnamespace A { trait One {} }\nnamespace { trait Root {} }\n", ['A\One', 'Root']];
        yield 'trait used as a name elsewhere' => [
            "<?php\nnamespace A;\nfinal class K { const trait = 1; public function trait(): void { \$this->trait(); \$x = self::trait; f(trait: 1); } }\n// trait Comment {}\n\$s = 'trait Str {}';\n",
            [],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('declarations')]
    public function test_trait_declarations_are_found_with_their_namespace(string $code, array $expected): void
    {
        self::assertSame($expected, self::traitsDeclaredInCode($code));
    }

    /**
     * @return list<string>
     */
    private static function traitsDeclaredIn(string $directory): array
    {
        $traits = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $traits = [...$traits, ...self::traitsDeclaredInCode((string) file_get_contents($file->getPathname()))];
            }
        }

        sort($traits);

        return $traits;
    }

    /**
     * @return list<string>
     */
    private static function traitsDeclaredInCode(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn(array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $namespace = '';
        $traits = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }
            $next = $tokens[$index + 1] ?? null;
            if ($token[0] === T_NAMESPACE) {
                $namespace = is_array($next) ? $next[1] . '\\' : ''; // `namespace {` is the global namespace
            }
            if ($token[0] === T_TRAIT && is_array($next) && $next[0] === T_STRING) {
                $traits[] = $namespace . $next[1];
            }
        }

        return $traits;
    }
}
