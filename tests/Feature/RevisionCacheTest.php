<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Compilation\CompiledShapes;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Illuminate\Testing\PendingCommand;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\InvalidDeclaration;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Discovery\DiscoveredDeclaration;
use Test\Fixtures\Discovery\Nested\NestedDeclaration;
use Test\Fixtures\Order;

final class RevisionCacheTest extends FeatureTestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/api-revisions-' . bin2hex(random_bytes(6)) . '.php';
        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.cache_path', $this->path);
        $config->set('revisions.discovery', [
            'paths' => [dirname(__DIR__) . '/Fixtures/Discovery'],
            'classes' => [OrderDeclaration::class, CustomerDeclaration::class],
        ]);
        CountingDeclaration::$described = 0;
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function test_artisan_optimize_builds_the_revisions_cache(): void
    {
        $this->command('optimize', ['--except' => 'config,events,routes,views'])->assertSuccessful();

        $compiled = CompiledShapes::load($this->path);
        // Discovered (response, body) + Nested (response) + Order (response) + Customer (response, body), 4 revisions
        self::assertSame(24, $compiled->count());
        self::assertSame(['name'], $compiled->get(DiscoveredDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis)?->names());
        self::assertSame(['id'], $compiled->get(NestedDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas)?->names());
        self::assertNotNull($compiled->get(CustomerDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Winter25));
    }

    public function test_optimize_clear_removes_the_revisions_cache(): void
    {
        $this->command('revision:cache')->assertSuccessful();
        self::assertFileExists($this->path);

        $this->command('optimize:clear', ['--except' => 'config,cache,compiled,events,routes,views'])->assertSuccessful();

        self::assertFileDoesNotExist($this->path);
    }

    public function test_the_cache_command_tells_how_many_shapes_it_wrote(): void
    {
        $this->command('revision:cache')
            ->expectsOutputToContain('24 revision shapes of 4 classes cached.')
            ->assertSuccessful();
    }

    public function test_the_shapes_of_the_application_are_read_from_the_compiled_file(): void
    {
        $this->application()['config']->set('revisions.discovery.classes', [CountingDeclaration::class]);
        $this->command('revision:cache')->assertSuccessful();
        $this->application()->forgetInstance(Shapes::class);
        CountingDeclaration::$described = 0;

        $shape = $this->application()->make(Shapes::class)->for(CountingDeclaration::class, DeclarationKind::RequestQuery, ApiRevision::Genesis);

        self::assertSame(['name'], $shape->names());
        self::assertSame(0, CountingDeclaration::$described);
    }

    public function test_a_class_that_does_not_compile_fails_the_command_and_keeps_the_previous_file(): void
    {
        file_put_contents($this->path, '<?php return [];');
        $this->application()['config']->set('revisions.discovery.classes', [InvalidDeclaration::class]);

        try {
            $this->command('revision:cache')->run();
            self::fail('The command should fail.');
        } catch (DefinitionException) {
            self::assertStringEqualsFile($this->path, '<?php return [];');
        }
    }

    public function test_clearing_without_compiled_file_succeeds(): void
    {
        $this->command('revision:clear')
            ->expectsOutputToContain('Compiled revision shapes cleared.')
            ->assertSuccessful();

        self::assertFileDoesNotExist($this->path);
    }

    public function test_an_explicit_class_not_revisioned_fails_the_command(): void
    {
        $this->application()['config']->set('revisions.discovery.classes', [Order::class]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('got ' . Order::class);

        $this->command('revision:cache')->run();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
