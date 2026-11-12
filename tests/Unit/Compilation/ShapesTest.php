<?php

declare(strict_types=1);

namespace Test\Unit\Compilation;

use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use RuntimeException;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Declarations\StoreOrderDeclaration;
use Test\TestCase;

final class ShapesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CountingDeclaration::$described = 0;
        CountingDeclaration::$fail = false;
    }

    public function test_a_shape_is_compiled_once_per_class_and_revision(): void
    {
        $shapes = new Shapes();

        $first = $shapes->for(CountingDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);
        $again = $shapes->for(CountingDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);

        self::assertSame($first, $again);
        self::assertSame(1, CountingDeclaration::$described);
        self::assertSame(['name'], $first->names());
    }

    public function test_each_kind_and_revision_has_its_own_shape(): void
    {
        $shapes = new Shapes();

        $body = $shapes->for(CountingDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);
        $query = $shapes->for(CountingDeclaration::class, DeclarationKind::RequestQuery, ApiRevision::Genesis);
        $head = $shapes->for(CountingDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Atlas);

        self::assertSame(DeclarationKind::RequestBody, $body->kind);
        self::assertSame(DeclarationKind::RequestQuery, $query->kind);
        self::assertSame(ApiRevision::Atlas, $head->revision);
        self::assertSame(['title'], $head->names());
        self::assertSame(3, CountingDeclaration::$described);
        self::assertSame($body, $shapes->for(CountingDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis));
    }

    public function test_flush_forgets_compiled_shapes(): void
    {
        $shapes = new Shapes();
        $first = $shapes->for(CountingDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);

        $shapes->flush();
        $again = $shapes->for(CountingDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);

        self::assertNotSame($first, $again);
        self::assertEquals($first, $again);
        self::assertSame(2, CountingDeclaration::$described);
    }

    public function test_a_failed_compilation_stores_nothing(): void
    {
        $shapes = new Shapes();
        CountingDeclaration::$fail = true;

        try {
            $shapes->for(CountingDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);
            self::fail('The compilation should throw.');
        } catch (RuntimeException) {
        }
        CountingDeclaration::$fail = false;

        self::assertSame(['name'], $shapes->for(CountingDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis)->names());
        self::assertSame(2, CountingDeclaration::$described);
    }

    public function test_shapes_are_compiled_by_the_compiler_given(): void
    {
        $shapes = new Shapes(new Compiler(new Configuration(dateTimeFormat: 'Y-m-d H:i')));

        $shape = $shapes->for(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas);

        self::assertSame('Y-m-d H:i', $shape->fields['placed_at']->type->format);
        self::assertEquals((new Compiler(new Configuration(dateTimeFormat: 'Y-m-d H:i')))->compile(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas), $shape);
    }

    public function test_two_repositories_never_share_a_shape(): void
    {
        $default = (new Shapes())->for(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas);
        $configured = (new Shapes(new Compiler(new Configuration(dateTimeFormat: 'Y-m-d H:i'))))->for(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas);

        self::assertSame('Y-m-d\TH:i:sP', $default->fields['placed_at']->type->format);
        self::assertSame('Y-m-d H:i', $configured->fields['placed_at']->type->format);
    }

    public function test_every_fixture_has_the_compiled_shape_of_every_revision(): void
    {
        $shapes = new Shapes();
        $compiler = new Compiler();

        foreach ([OrderDeclaration::class, CustomerDeclaration::class, StoreOrderDeclaration::class] as $class) {
            foreach ($class::declarationKinds() as $kind) {
                foreach (ApiRevision::cases() as $revision) {
                    self::assertEquals($compiler->compile($class, $kind, $revision), $shapes->for($class, $kind, $revision));
                }
            }
        }
    }
}
