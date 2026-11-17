<?php

declare(strict_types=1);

namespace Test\Unit;

use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Conversion\Cast;
use Ark4ne\ApiRevisions\Conversion\Converter;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\RevisionsException;
use Ark4ne\ApiRevisions\Export\DatePattern;
use Ark4ne\ApiRevisions\Export\RegexPattern;
use Ark4ne\ApiRevisions\Laravel\Http\ResolveRevision;
use Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver;
use Ark4ne\ApiRevisions\Laravel\Requests\RevisionedRequest;
use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\Unrevisioned;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\DeclaresConversions;
use Ark4ne\ApiRevisions\Schema\DescribesSchema;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\InvalidValue;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use Ark4ne\ApiRevisions\Testing\InteractsWithRevisions;
use Ark4ne\ApiRevisions\Upgrade\UpgradesInput;
use Attribute;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Console\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;
use Test\TestCase;

/**
 * The agent skill shipped in `skills/laravel-api-revisions/`. Its reference must name every public entry
 * point of the package, derived from the code so that a new one fails here until it is documented:
 *
 * - the static helpers of `Schema`, `Cast` and `TypeRegistry`;
 * - the fluent modifiers (public methods returning `static` or `self`) of `Descriptor` and of every concrete schema class;
 * - the methods of the contracts an application implements, of the traits it uses, and of `Converters`;
 * - every command, with each of its options, and every key of `config/revisions.php`;
 * - by their full name: every interface, trait and attribute of `src/`, every exception, every resolver, and the
 *   concrete classes an application names.
 *
 * Every PHP snippet of the recipes is taken from a file of `tests/` (a fixture of `tests/Fixtures/Recipes/`, or
 * `RecipesTest`, which runs them), its namespace `Test\Fixtures\Recipes\` read as `App\` and its lines possibly dedented.
 */
final class SkillTest extends TestCase
{
    private const string SKILL = __DIR__ . '/../../skills/laravel-api-revisions';

    private const string TESTS = __DIR__ . '/..';

    /**
     * The concrete classes an application names besides the contracts: helpers, extension bases and the core API.
     */
    private const array NAMED_CLASSES = [
        Schema::class,
        RegexPattern::class,
        DatePattern::class,
        Cast::class,
        Converters::class,
        Direction::class,
        TypeRegistry::class,
        Descriptor::class,
        InvalidValue::class,
        TypeDefinition::class,
        Kind::class,
        DeclarationKind::class,
        Shapes::class,
        Projector::class,
        ResolveRevision::class,
        Unrevisioned::class,
        RevisionsConfig::class,
    ];

    /**
     * The contracts an application implements, whose methods it writes.
     */
    private const array APPLICATION_CONTRACTS = [
        Revision::class,
        RevisionResolver::class,
        Converter::class,
        DeclaresConversions::class,
        DescribesSchema::class,
        UpgradesInput::class,
    ];

    /**
     * The traits an application uses, whose non-private methods it calls or overrides.
     */
    private const array APPLICATION_TRAITS = [
        IsRevision::class,
        RevisionedResource::class,
        RevisionedRequest::class,
        InteractsWithRevisions::class,
    ];

    public function test_the_skill_declares_its_name_and_when_to_use_it(): void
    {
        $skill = self::read('SKILL.md');

        self::assertMatchesRegularExpression('/\A---\nname: laravel-api-revisions\ndescription: \S[^\n]{80,}\n---\n/', $skill);
        self::assertStringContainsString('(reference.md)', $skill);
        self::assertStringContainsString('(recipes.md)', $skill);
    }

    /**
     * @param list<string> $methods
     */
    #[DataProvider('entryMethods')]
    public function test_the_reference_names_every_public_method(string $owner, array $methods): void
    {
        self::assertNotEmpty($methods);
        $missing = array_values(array_filter(
            $methods,
            static fn(string $method): bool => preg_match('/(?<![\w$])' . preg_quote($method, '/') . '\(/', self::read('reference.md')) !== 1,
        ));

        self::assertSame([], $missing, "The reference does not name these methods of {$owner}.");
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function entryMethods(): iterable
    {
        foreach ([Schema::class, Cast::class, TypeRegistry::class] as $class) {
            yield $class => [$class, self::methods($class, static fn(ReflectionMethod $method): bool => $method->isStatic() && $method->isPublic())];
        }

        foreach (self::schemaClasses() as $class) {
            yield $class => [$class, self::methods($class, static fn(ReflectionMethod $method): bool => !$method->isStatic() && $method->isPublic() && self::returnsStatic($method))];
        }

        foreach (self::APPLICATION_CONTRACTS as $contract) {
            yield $contract => [$contract, self::methods($contract, static fn(): bool => true)];
        }

        yield Converters::class => [Converters::class, self::methods(Converters::class, static fn(ReflectionMethod $method): bool => $method->isPublic())];

        foreach (self::APPLICATION_TRAITS as $trait) {
            yield $trait => [$trait, self::methods($trait, static fn(ReflectionMethod $method): bool => !$method->isPrivate() && !str_starts_with($method->getName(), 'setUp'))];
        }
    }

    public function test_the_reference_names_every_command_and_option(): void
    {
        $commands = [];

        foreach (self::sourceClasses() as $class) {
            if (is_subclass_of($class, Command::class)) {
                $signature = (new ReflectionClass($class))->getProperty('signature')->getDefaultValue();
                self::assertIsString($signature);
                [$name, , $options] = Parser::parse($signature);
                $commands[$name] = '/`' . preg_quote($name, '/') . '`/';
                foreach ($options as $option) {
                    $commands["{$name} --{$option->getName()}"] = '/^\\| `' . preg_quote($name, '/') . '` \\|.*`--' . preg_quote($option->getName(), '/') . '[=`]/m';
                }
            }
        }

        self::assertArrayHasKey('revision:check --format', $commands);
        self::assertSame([], array_keys(array_filter($commands, static fn(string $pattern): bool => preg_match($pattern, self::read('reference.md')) !== 1)));
    }

    public function test_the_reference_names_every_configuration_key(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 2) . '/config/revisions.php');
        preg_match_all("/^    '(\\w+)' =>/m", $config, $keys);
        preg_match("/^    'discovery' => \\[\\n(.*?)^    \\],/ms", $config, $block);
        preg_match_all("/^        '(\\w+)' =>/m", $block[1] ?? '', $discovery);
        $names = [...array_diff($keys[1], ['discovery']), ...array_map(static fn(string $key): string => "discovery.{$key}", $discovery[1])];

        self::assertContains('discovery.classes', $names);
        self::assertSame([], array_values(array_filter($names, static fn(string $key): bool => !str_contains(self::read('reference.md'), "`{$key}`"))));
    }

    public function test_the_reference_names_every_public_type_by_its_full_name(): void
    {
        $types = [...self::NAMED_CLASSES];

        foreach (self::sourceClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isInterface() || $reflection->isTrait() || $reflection->getAttributes(Attribute::class) !== []
                || ($reflection->isInstantiable() && ($reflection->isSubclassOf(RevisionsException::class) || $reflection->implementsInterface(RevisionResolver::class)))) {
                $types[] = $class;
            }
        }

        self::assertContains(RevisionResolver::class, $types);
        self::assertSame([], array_values(array_filter(array_unique($types), static fn(string $type): bool => !str_contains(self::read('reference.md'), $type))));
    }

    public function test_every_recipe_snippet_is_taken_from_a_tested_fixture(): void
    {
        $recipes = self::read('recipes.md');
        preg_match_all('/(?:<!-- tested: ([\w\/]+\.php) -->\n)?^```php\n(.*?)^```$/ms', $recipes, $blocks, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(8, count($blocks));

        foreach ($blocks as $index => $block) {
            self::assertNotSame('', $block[1], "Snippet #{$index} names no tested file.");
            $source = self::TESTS . '/' . $block[1];
            self::assertFileExists($source);

            self::assertStringContainsString(self::lines($block[2]), self::lines(self::asApplicationCode((string) file_get_contents($source))), "Snippet #{$index} differs from {$block[1]}.");
        }
    }

    public function test_every_skill_file_links_only_to_files_that_exist(): void
    {
        foreach (['SKILL.md', 'reference.md', 'recipes.md'] as $file) {
            preg_match_all('/\]\(([\w.\/-]+\.md)(#[\w-]+)?\)/', self::read($file), $links);

            foreach ($links[1] as $link) {
                self::assertFileExists(self::SKILL . '/' . $link, "{$file} links to {$link}.");
            }
        }
    }

    /**
     * The fixture as an application writes it: its namespace `Test\Fixtures\Recipes\` is `App\`.
     */
    private static function asApplicationCode(string $source): string
    {
        return str_replace('Test\\Fixtures\\Recipes\\', 'App\\', $source);
    }

    /**
     * The lines of `$code` without their indentation: a snippet may be dedented.
     */
    private static function lines(string $code): string
    {
        return implode("\n", array_map(trim(...), explode("\n", trim($code))));
    }

    private static function read(string $file): string
    {
        $path = self::SKILL . '/' . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @param class-string $class
     * @param callable(ReflectionMethod): bool $filter
     *
     * @return list<string>
     */
    private static function methods(string $class, callable $filter): array
    {
        $methods = array_filter(
            (new ReflectionClass($class))->getMethods(),
            static fn(ReflectionMethod $method): bool => !str_starts_with($method->getName(), '__')
                && !str_contains((string) $method->getDocComment(), '@internal')
                && $filter($method),
        );

        return array_values(array_unique(array_map(static fn(ReflectionMethod $method): string => $method->getName(), $methods)));
    }

    private static function returnsStatic(ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();

        return $type instanceof ReflectionNamedType && in_array($type->getName(), ['static', 'self'], true);
    }

    /**
     * @return list<class-string<Descriptor<mixed>>>
     */
    private static function schemaClasses(): array
    {
        $classes = [Descriptor::class];

        foreach (self::sourceClasses() as $class) {
            if (is_subclass_of($class, Descriptor::class) && !(new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return list<class-string>
     */
    private static function sourceClasses(): array
    {
        $root = dirname(__DIR__, 2) . '/src/';
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php' || str_starts_with($file->getPathname(), $root . 'PHPStan/')) {
                continue;
            }

            /** @var class-string $class */
            $class = 'Ark4ne\\ApiRevisions\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
