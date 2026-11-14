<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Checking\Declarations;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use PHPUnit\Framework\TestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\PodcastDeclaration;
use Test\Fixtures\Declarations\ShowDeclaration;
use Test\Fixtures\Declarations\StoreOrderDeclaration;

final class DeclarationsTest extends TestCase
{
    public function test_it_lists_every_found_class_with_its_kinds_in_the_finder_order(): void
    {
        $declarations = new Declarations(self::finder([ShowDeclaration::class, StoreOrderDeclaration::class, PodcastDeclaration::class]));

        self::assertSame([
            ShowDeclaration::class => [DeclarationKind::Response],
            StoreOrderDeclaration::class => [DeclarationKind::RequestBody, DeclarationKind::RequestQuery],
            PodcastDeclaration::class => [DeclarationKind::Response],
        ], $declarations->declarations());
    }

    public function test_at_a_revision_it_lists_the_classes_existing_at_it_only(): void
    {
        $declarations = new Declarations(self::finder([PodcastDeclaration::class, ShowDeclaration::class]));

        self::assertSame([ShowDeclaration::class => [DeclarationKind::Response]], $declarations->at(ApiRevision::Winter25));
        self::assertSame([
            PodcastDeclaration::class => [DeclarationKind::Response],
            ShowDeclaration::class => [DeclarationKind::Response],
        ], $declarations->at(ApiRevision::Spring26));
    }

    public function test_it_lists_nothing_when_nothing_is_found(): void
    {
        $declarations = new Declarations(self::finder([]));

        self::assertSame([], $declarations->declarations());
        self::assertSame([], $declarations->at(ApiRevision::Atlas));
    }

    /**
     * @param list<class-string<Revisioned>> $classes
     */
    private static function finder(array $classes): ClassFinder
    {
        return new readonly class ($classes) implements ClassFinder {
            /**
             * @param list<class-string<Revisioned>> $classes
             */
            public function __construct(private array $classes) {}

            public function find(): array
            {
                return $this->classes;
            }
        };
    }
}
