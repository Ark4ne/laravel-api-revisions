<?php

declare(strict_types=1);

namespace Test\Architecture;

use PHPat\Selector\Classname;
use PHPat\Selector\ClassNamespace;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule as ArchitectureRule;
use PHPat\Test\PHPat;

/**
 * Architecture rules of docs/architecture.md, run by PHPStan through phpat (`composer stan`), never by PHPUnit.
 *
 * The list of allowed traits is checked by Test\Unit\Architecture\DeclaredTraitsTest: phpat cannot see a trait
 * declaration (PHPStan analyses a trait in the context of the classes using it).
 */
final class ArchitectureTest
{
    private const ROOT = 'Ark4ne\ApiRevisions';

    /**
     * Layers from left to right: each one depends only on those to its left (docs/architecture.md §2).
     */
    private const LAYERS = [
        'Support',
        'Configuration',
        'Revisions',
        'Definition',
        'Conversion',
        'Schema',
        'Compilation',
        'Projection',
        'Upgrade',
        'Export',
        'Checking',
        'Laravel',
        'Testing',
    ];

    /**
     * The pure core: every layer left of Laravel (docs/architecture.md §2).
     */
    private const CORE = [
        'Support',
        'Configuration',
        'Revisions',
        'Definition',
        'Conversion',
        'Schema',
        'Compilation',
        'Projection',
        'Upgrade',
        'Export',
        'Checking',
        'Exceptions',
    ];

    /**
     * Extensible base classes allowed by docs/architecture.md §16.
     */
    private const BASE_CLASSES = [
        'Ark4ne\ApiRevisions\Schema\Descriptor',
    ];

    /**
     * @return iterable<ArchitectureRule>
     */
    public function test_layers_depend_only_on_the_layers_to_their_left(): iterable
    {
        foreach (self::LAYERS as $position => $layer) {
            $right = array_slice(self::LAYERS, $position + 1);

            if ($right === []) {
                continue;
            }

            yield PHPat::rule()
                ->classes(self::layer($layer))
                ->shouldNotDependOn()
                ->classes(...self::layers(...$right))
                ->because("{$layer} depends only on the layers to its left.");
        }
    }

    public function test_exceptions_depend_only_on_support(): ArchitectureRule
    {
        return PHPat::rule()
            ->classes(self::layer('Exceptions'))
            ->shouldNotDependOn()
            ->classes(...self::layers(...array_slice(self::LAYERS, 1)))
            ->because('exceptions are usable by every layer.');
    }

    public function test_no_layer_depends_on_the_phpstan_tooling(): ArchitectureRule
    {
        return PHPat::rule()
            ->classes(...self::layers(...self::LAYERS, ...['Exceptions']))
            ->shouldNotDependOn()
            ->classes(self::layer('PHPStan'), Selector::inNamespace('PHPStan'))
            ->because('src/PHPStan/ is loaded only by PHPStan, a dev dependency: never at run time.');
    }

    public function test_the_phpstan_tooling_depends_only_on_schema(): ArchitectureRule
    {
        return PHPat::rule()
            ->classes(self::layer('PHPStan'))
            ->shouldNotDependOn()
            ->classes(...self::layers(...array_values(array_diff(self::LAYERS, ['Schema']))))
            ->because('the extension only reads the custom types, which are descriptors.');
    }

    public function test_the_core_never_depends_on_illuminate(): ArchitectureRule
    {
        return PHPat::rule()
            ->classes(...self::layers(...self::CORE))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Illuminate'))
            ->because('the core is pure PHP: only src/Laravel/ and src/Testing/ know Laravel.');
    }

    public function test_definitions_are_readonly(): ArchitectureRule
    {
        return PHPat::rule()
            ->classes(self::layer('Definition'))
            ->excluding(Selector::isEnum(), Selector::isInterface())
            ->shouldBeReadonly()
            ->because('definitions are final readonly value objects, the public contract read by the tools (docs/architecture.md §4).');
    }

    public function test_classes_are_final(): ArchitectureRule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ROOT))
            ->excluding(
                Selector::isInterface(),
                Selector::isEnum(),
                Selector::isTrait(),
                ...self::classnames(self::BASE_CLASSES),
            )
            ->shouldBeFinal()
            ->because('classes are final by default; a new extensible base class is added to docs/architecture.md §16 and to BASE_CLASSES, never by loosening this rule.');
    }

    public function test_only_the_base_classes_are_abstract(): ArchitectureRule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ROOT))
            ->excluding(
                Selector::isInterface(),
                ...self::classnames(self::BASE_CLASSES),
            )
            ->shouldNotBeAbstract()
            ->because('abstract classes are reserved for the base classes of docs/architecture.md §16 (BASE_CLASSES).');
    }

    public function test_no_class_of_the_package_uses_a_trait(): ArchitectureRule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ROOT))
            ->shouldNotInclude()
            ->classes(Selector::all())
            ->because('the package uses composition; its traits are declared for applications only.');
    }

    public function test_interfaces_have_no_interface_suffix(): ArchitectureRule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf(Selector::inNamespace(self::ROOT), Selector::isInterface()))
            ->shouldBeNamed('/^(?!.*Interface$).*$/', true)
            ->because('interfaces are named after a role: Converter, Check, RevisionResolver.');
    }

    private static function layer(string $layer): ClassNamespace
    {
        return Selector::inNamespace(self::ROOT . '\\' . $layer);
    }

    /**
     * @return list<ClassNamespace>
     */
    private static function layers(string ...$layers): array
    {
        return array_values(array_map(self::layer(...), $layers));
    }

    /**
     * @param list<non-empty-string> $classes
     *
     * @return list<Classname>
     */
    private static function classnames(array $classes): array
    {
        return array_map(static fn(string $class): Classname => Selector::classname($class), $classes);
    }
}
