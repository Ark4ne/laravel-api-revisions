<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Discovery;

use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Laravel\Discovery\PathClassFinder;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Discovery\AbstractDeclaration;
use Test\Fixtures\Discovery\Declares;
use Test\Fixtures\Discovery\DiscoveredDeclaration;
use Test\Fixtures\Discovery\Nested\NestedDeclaration;
use Test\Fixtures\Discovery\NotRevisioned;
use Test\TestCase;

final class PathClassFinderTest extends TestCase
{
    private const string PATH = __DIR__ . '/../../../Fixtures/Discovery';

    private const string GLOBAL = __DIR__ . '/../../../Fixtures/GlobalDeclaration.php';

    public function test_the_concrete_revisioned_classes_under_the_paths_are_found(): void
    {
        self::assertSame(
            [DiscoveredDeclaration::class, NestedDeclaration::class],
            (new PathClassFinder([self::PATH]))->find(),
        );
    }

    public function test_explicit_classes_outside_the_paths_are_added(): void
    {
        self::assertSame(
            [OrderDeclaration::class, DiscoveredDeclaration::class, NestedDeclaration::class],
            (new PathClassFinder([self::PATH], [OrderDeclaration::class]))->find(),
        );
    }

    public function test_a_class_found_twice_is_listed_once(): void
    {
        self::assertSame(
            [DiscoveredDeclaration::class, NestedDeclaration::class],
            (new PathClassFinder([self::PATH, self::PATH . '/Nested', self::PATH . '/DiscoveredDeclaration.php'], [NestedDeclaration::class]))->find(),
        );
    }

    public function test_a_path_to_a_file_gives_its_class(): void
    {
        self::assertSame([DiscoveredDeclaration::class], (new PathClassFinder([self::PATH . '/DiscoveredDeclaration.php']))->find());
    }

    public function test_a_path_that_does_not_exist_gives_no_class(): void
    {
        self::assertSame([], (new PathClassFinder([self::PATH . '/Missing']))->find());
    }

    public function test_a_file_declaring_no_class_gives_none(): void
    {
        self::assertSame([], (new PathClassFinder([__DIR__ . '/../../../../config/revisions.php']))->find());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notDeclaring(): iterable
    {
        yield 'missing' => ['App\\Missing'];
        yield 'not revisioned' => [NotRevisioned::class];
        yield 'abstract' => [AbstractDeclaration::class];
        yield 'an interface' => [Declares::class];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notDeclaring')]
    public function test_an_explicit_class_that_is_not_a_concrete_revisioned_class_is_refused(string $class): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("revisions.discovery.classes must be a list of concrete classes implementing Ark4ne\\ApiRevisions\\Schema\\Revisioned, got {$class}.");

        (new PathClassFinder([], [$class]))->find();
    }

    public function test_a_class_in_the_global_namespace_has_no_leading_backslash(): void
    {
        require_once self::GLOBAL;

        self::assertSame(['GlobalDeclaration'], (new PathClassFinder([self::GLOBAL]))->find());
    }
}
