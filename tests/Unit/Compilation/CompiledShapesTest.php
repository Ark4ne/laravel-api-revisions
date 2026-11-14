<?php

declare(strict_types=1);

namespace Test\Unit\Compilation;

use Ark4ne\ApiRevisions\Compilation\CompiledShapes;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Exceptions\CacheException;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use DateTimeImmutable;
use stdClass;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Customer;
use Test\Fixtures\Declarations\BoundDeclaration;
use Test\Fixtures\Declarations\ContactDeclaration;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Declarations\OrderInputDeclaration;
use Test\Fixtures\Declarations\StoreOrderDeclaration;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Order;
use Test\TestCase;

final class CompiledShapesTest extends TestCase
{
    private const array DECLARATIONS = [
        OrderDeclaration::class,
        CustomerDeclaration::class,
        StoreOrderDeclaration::class,
        OrderInputDeclaration::class,
        ContactDeclaration::class,
        BoundDeclaration::class,
        CountingDeclaration::class,
    ];

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/api-revisions-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ($this->files() as $file) {
            unlink($file);
        }
        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_a_cached_shape_equals_the_compiled_one(): void
    {
        $compiler = new Compiler();
        $shapes = [];
        foreach (self::DECLARATIONS as $class) {
            foreach ($class::declarationKinds() as $kind) {
                foreach (ApiRevision::order() as $revision) {
                    $shapes[] = $compiler->compile($class, $kind, $revision);
                }
            }
        }

        CompiledShapes::write($this->path(), $shapes);
        $compiled = CompiledShapes::load($this->path());

        self::assertSame(count($shapes), $compiled->count());
        foreach ($shapes as $shape) {
            self::assertNotNull($shape->class);
            self::assertNotNull($shape->kind);
            self::assertEquals($shape, $compiled->get($shape->class, $shape->kind, $shape->revision));
        }
    }

    public function test_a_cached_lookup_loads_neither_the_declaring_class_nor_any_descriptor(): void
    {
        $compiler = new Compiler();
        CompiledShapes::write($this->path(), [$compiler->compile(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25)]);

        $output = shell_exec(sprintf('%s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/Scripts/lookup-shape.php'), escapeshellarg($this->path())));
        self::assertIsString($output);
        $loaded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($loaded);

        self::assertContains(Shape::class, $loaded);
        foreach ($loaded as $class) {
            self::assertIsString($class);
            self::assertFalse(is_subclass_of($class, Descriptor::class) || $class === Descriptor::class, "{$class} is a descriptor");
            self::assertFalse(is_subclass_of($class, Revisioned::class), "{$class} is a declaring class");
        }
    }

    public function test_projection_from_the_cache_runs_the_closures_of_the_instance(): void
    {
        $compiler = new Compiler();
        CompiledShapes::write($this->path(), [
            $compiler->compile(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25),
            $compiler->compile(CustomerDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25),
        ]);
        $cached = new Shapes(compiled: CompiledShapes::load($this->path()));
        $order = self::order();

        $projected = (new Projector(shapes: $cached))->project(
            $cached->for(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25),
            OrderDeclaration::describeRevisioned(DeclarationKind::Response, $order),
            $order,
        );

        $fresh = new Shapes();
        self::assertSame((new Projector(shapes: $fresh))->project(
            $fresh->for(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25),
            OrderDeclaration::describeRevisioned(DeclarationKind::Response, $order),
            $order,
        ), $projected);
        self::assertSame('12.50', $projected['total']);
        self::assertSame('2026-03-01', $projected['placed_at']);
        self::assertSame('Ada Lovelace', $projected['name']);
    }

    public function test_the_file_returns_serialized_shapes_by_key(): void
    {
        $shape = (new Compiler())->compile(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis);

        CompiledShapes::write($this->path(), [$shape]);

        $key = CompiledShapes::key(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis);
        self::assertSame(['@format' => (string) CompiledShapes::FORMAT, $key => serialize($shape)], require $this->path());
        self::assertSame('Test\Fixtures\Declarations\CountingDeclaration@response@Test\Fixtures\ApiRevision::Genesis', $key);
    }

    public function test_writing_replaces_the_previous_file(): void
    {
        $compiler = new Compiler();
        CompiledShapes::write($this->path(), [
            $compiler->compile(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis),
            $compiler->compile(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas),
        ]);

        CompiledShapes::write($this->path(), [$compiler->compile(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas)]);

        $compiled = CompiledShapes::load($this->path());
        self::assertSame(1, $compiled->count());
        self::assertNull($compiled->get(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis));
        self::assertSame(['title'], $compiled->get(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas)?->names());
        self::assertSame([basename($this->path())], array_map(basename(...), $this->files()));
    }

    public function test_a_missing_file_holds_no_shape(): void
    {
        $compiled = CompiledShapes::load($this->path());

        self::assertSame(0, $compiled->count());
        self::assertNull($compiled->get(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis));
        self::assertSame(0, CompiledShapes::empty()->count());
    }

    public function test_a_shape_not_in_the_file_is_null(): void
    {
        CompiledShapes::write($this->path(), [(new Compiler())->compile(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis)]);

        $compiled = CompiledShapes::load($this->path());

        self::assertNull($compiled->get(CountingDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis));
        self::assertNull($compiled->get(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25));
        self::assertNull($compiled->get(BoundDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis));
    }

    public function test_a_directory_that_does_not_exist_cannot_be_written(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("The compiled shapes file {$this->directory}/missing/revisions.php cannot be written.");

        CompiledShapes::write("{$this->directory}/missing/revisions.php", []);
    }

    public function test_a_read_only_directory_cannot_be_written_and_keeps_no_temporary_file(): void
    {
        chmod($this->directory, 0o555);

        try {
            CompiledShapes::write($this->path(), []);
            self::fail('The write should fail.');
        } catch (CacheException $exception) {
            self::assertSame("The compiled shapes file {$this->path()} cannot be written.", $exception->getMessage());
        } finally {
            chmod($this->directory, 0o755);
        }
        self::assertSame([], $this->files());
    }

    public function test_a_file_in_place_is_replaced_and_keeps_no_temporary_file(): void
    {
        file_put_contents($this->path(), '<?php return [];');

        CompiledShapes::write($this->path(), [(new Compiler())->compile(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis)]);

        self::assertSame(1, CompiledShapes::load($this->path())->count());
        self::assertSame([$this->path()], $this->files());
    }

    public function test_a_shape_without_its_declaration_cannot_be_written(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Only the shapes compiled from a class can be cached.');

        CompiledShapes::write($this->path(), [new Shape(ApiRevision::Genesis, Direction::Down, [])]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'not an array' => ['<?php return 1;'];
        yield 'without format' => ['<?php return [];'];
        yield 'of another format' => ["<?php return ['@format' => '0'];"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFiles')]
    public function test_a_file_returning_something_else_is_refused(string $content): void
    {
        file_put_contents($this->path(), $content);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("The compiled shapes file {$this->path()} is invalid: run revision:cache again.");

        CompiledShapes::load($this->path());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'not a shape' => [serialize(new stdClass())];
        yield 'corrupted' => ['O:3:"Foo'];
        yield 'not a string' => [1];
        yield 'null' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEntries')]
    public function test_an_entry_that_is_not_a_shape_is_refused_when_first_read(mixed $entry): void
    {
        $key = CompiledShapes::key(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis);
        file_put_contents($this->path(), '<?php return ' . var_export(['@format' => (string) CompiledShapes::FORMAT, $key => $entry], true) . ';');
        $compiled = CompiledShapes::load($this->path());
        self::assertNull($compiled->get(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas));

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage("The compiled shapes file {$this->path()} is invalid: run revision:cache again.");

        $compiled->get(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis);
    }

    public function test_an_entry_under_an_integer_key_is_never_read(): void
    {
        file_put_contents($this->path(), '<?php return ' . var_export(['@format' => (string) CompiledShapes::FORMAT, 'not a key'], true) . ';');
        $compiled = CompiledShapes::load($this->path());

        self::assertSame(1, $compiled->count());
        self::assertNull($compiled->get(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis));
    }

    private function path(): string
    {
        return "{$this->directory}/revisions.php";
    }

    private static function order(): Order
    {
        return new Order(
            'Book',
            OrderStatus::Paid,
            1250,
            new DateTimeImmutable('2026-03-01 10:30:00+00:00'),
            new DateTimeImmutable('2026-03-04'),
            ['name' => 'Ada Lovelace', 'orders' => []],
            ['gift'],
            null,
            new Customer('Ada', 'Lovelace'),
        );
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob("{$this->directory}/*");
        self::assertIsArray($files);

        return $files;
    }
}
