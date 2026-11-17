<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Discovery;

use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * The declaring classes of an application: the concrete classes implementing `Schema\Revisioned`
 * declared in the PHP files under `discovery.paths`, plus the `discovery.classes` as given (packages, modules). Used
 * by `revision:cache` and `revision:check`. A path that does not exist gives no class; a given class that is
 * not a concrete `Revisioned` class throws `ConfigurationException`. The classes of a file are read from its tokens, then autoloaded.
 */
final readonly class PathClassFinder implements ClassFinder
{
    /**
     * @param list<string> $paths directories scanned recursively, or PHP files
     * @param list<string> $classes classes added as given
     */
    public function __construct(
        private array $paths,
        private array $classes = [],
    ) {}

    /**
     * Every class found, each once, sorted.
     *
     * @return list<class-string<Revisioned>>
     */
    public function find(): array
    {
        $found = [];
        foreach ($this->classes as $class) {
            if (!self::declares($class)) {
                throw ConfigurationException::invalidValue('discovery.classes', 'a list of concrete classes implementing Ark4ne\\ApiRevisions\\Schema\\Revisioned', $class);
            }
            $found[$class] = true;
        }
        foreach ($this->paths as $path) {
            foreach (self::files($path) as $file) {
                foreach (self::classesIn($file) as $class) {
                    if (self::declares($class)) {
                        $found[$class] = true;
                    }
                }
            }
        }
        $found = array_keys($found);
        sort($found);

        return $found;
    }

    /**
     * Whether `$class` is a concrete class implementing `Revisioned` (autoloaded).
     *
     * @phpstan-assert-if-true class-string<Revisioned> $class
     */
    private static function declares(string $class): bool
    {
        return class_exists($class) && is_subclass_of($class, Revisioned::class) && !(new ReflectionClass($class))->isAbstract();
    }

    /**
     * The PHP files at `$path`: itself when a file, those under it when a directory.
     *
     * @return list<string>
     */
    private static function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        if (!is_dir($path)) {
            return [];
        }
        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * The fully qualified names of the classes declared in the file (never an anonymous class, never `Foo::class`).
     *
     * @return list<string>
     */
    private static function classesIn(string $file): array
    {
        $tokens = array_values(PhpToken::tokenize((string) file_get_contents($file)));
        $namespace = '';
        $classes = [];
        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = self::nextName($tokens, $index) . '\\';
            } elseif ($token->is(T_CLASS)) {
                // an anonymous class (`new class`) and a `Foo::class` are followed by no name
                $name = self::nextName($tokens, $index);
                if ($name !== '') {
                    // a global `namespace { }` block gives the prefix `\`
                    $classes[] = ltrim($namespace . $name, '\\');
                }
            }
        }

        return $classes;
    }

    /**
     * The name following the token at `$index`, `''` when the next meaningful token is not a name.
     *
     * @param list<PhpToken> $tokens
     */
    private static function nextName(array $tokens, int $index): string
    {
        for ($i = $index + 1; isset($tokens[$i]); ++$i) {
            if (!$tokens[$i]->isIgnorable()) {
                return $tokens[$i]->is([T_STRING, T_NAME_QUALIFIED]) ? $tokens[$i]->text : '';
            }
        }

        return '';
    }
}
