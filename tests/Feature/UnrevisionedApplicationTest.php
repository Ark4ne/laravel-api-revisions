<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\PathResolver;
use Ark4ne\ApiRevisions\Laravel\Queue\CapturedRevisionHandler;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\Unrevisioned;
use Ark4ne\ApiRevisions\Testing\InteractsWithRevisions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use LogicException;
use Test\FeatureTestCase;
use Test\Fixtures\Unrevisioned\PostJob;
use Test\Fixtures\Unrevisioned\PostResource;
use Test\Fixtures\Unrevisioned\StorePostRequest;
use Test\TemporaryDirectory;

/**
 * An application installing the library without configuring a revision enum (US 12.2, model.md D80): it renders,
 * validates and runs every command on the implicit revision, and stays invisible to its clients.
 */
final class UnrevisionedApplicationTest extends FeatureTestCase
{
    use InteractsWithRevisions;
    use TemporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.discovery', ['paths' => [], 'classes' => [PostResource::class, StorePostRequest::class]]);
        $config->set('revisions.lock_path', $this->temporary . '/lock');
        $config->set('revisions.cache_path', $this->temporary . '/revisions.php');
        mkdir($this->temporary);
        PostJob::$ran = [];

        $router = $this->application()->make(Router::class);
        $router->group(['middleware' => 'revision'], static function (Router $router): void {
            $router->get('/notes', static fn(): PostResource => PostResource::make(['title' => 'Tides', 'views' => 3]));
            $router->post('/notes', static fn(StorePostRequest $request): array => $request->validated());
        });
    }

    public function test_a_resource_renders_without_any_revision_header(): void
    {
        $response = $this->getJson('/notes', ['Api-Revision' => 'not-a-revision'])
            ->assertOk()
            ->assertExactJson(['data' => ['title' => 'Tides', 'views' => 3]]);

        self::assertFalse($response->headers->has('Api-Revision'));
        self::assertFalse($response->headers->has('Vary'));
        self::assertFalse($response->headers->has('Deprecation'));
    }

    public function test_the_testing_helpers_validate_the_one_revision(): void
    {
        $this->getJson('/notes')->assertMatchesRevisionSchema(PostResource::class);
        $this->assertRendersAtEveryRevision(PostResource::class, ['title' => 'Tides', 'views' => 3]);
        $this->assertRevisionsAreConsistent();
    }

    public function test_with_revision_tells_that_no_enum_is_configured(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('set revisions.enum to the revision enum of the API first');

        $this->withRevision(Unrevisioned::Head);
    }

    public function test_path_mode_tells_that_no_enum_is_configured(): void
    {
        $this->application()['config']->set('revisions.resolvers', [PathResolver::class => []]);
        $this->application()->forgetInstance(RevisionsConfig::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('set revisions.enum to the revision enum of the API first');

        $this->application()->make(Router::class)->revisioned(static function (): void {});
    }

    public function test_a_job_captured_before_the_enum_was_removed_runs_in_the_one_revision(): void
    {
        $this->jobsTable();
        PostJob::dispatch();
        $payload = DB::table('jobs')->value('payload');
        self::assertIsString($payload);
        $captured = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($captured);
        // as the payload hook wrote it while an enum was configured
        $captured['job'] = CapturedRevisionHandler::class . '@call';
        $captured[CapturedRevisionHandler::KEY] = 'winter-25';
        DB::table('jobs')->update(['payload' => json_encode($captured, JSON_THROW_ON_ERROR)]);
        $stored = DB::table('jobs')->value('payload');
        self::assertIsString($stored);
        self::assertStringContainsString('winter-25', $stored);

        $this->command('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful()->run();

        self::assertSame([Unrevisioned::Head], PostJob::$ran);
    }

    public function test_a_request_is_validated_and_read_from_validated(): void
    {
        $this->postJson('/notes', ['title' => 'Tides', 'views' => 3, 'extra' => true])->assertExactJson(['title' => 'Tides', 'views' => 3]);
        $this->postJson('/notes', ['views' => -1])->assertUnprocessable()->assertJsonValidationErrors(['title', 'views']);
    }

    public function test_a_queued_job_captures_no_revision_and_runs(): void
    {
        $this->jobsTable();

        PostJob::dispatch();
        $stored = DB::table('jobs')->value('payload');
        self::assertIsString($stored);
        $payload = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayNotHasKey(CapturedRevisionHandler::KEY, $payload);
        $this->command('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful()->run();

        self::assertSame([Unrevisioned::Head], PostJob::$ran);
    }

    public function test_optimize_caches_the_one_revision(): void
    {
        $this->command('optimize', ['--except' => 'config,events,routes,views'])->assertSuccessful();

        self::assertFileExists($this->temporary . '/revisions.php');
    }

    public function test_every_command_succeeds_on_the_one_revision(): void
    {
        $kernel = $this->application()->make(Kernel::class);
        foreach (['revision:check' => [], 'revision:lock' => [], 'revision:export' => [], 'revision:changelog' => [], 'revision:prune' => [], 'revision:clear' => []] as $command => $parameters) {
            self::assertSame(0, $kernel->call($command, $parameters), "{$command}: {$kernel->output()}");
        }

        self::assertFileDoesNotExist($this->temporary . '/lock');
        $kernel->call('revision:prune');
        self::assertStringContainsString('Nothing to prune.', $kernel->output());
        $kernel->call('revision:changelog');
        self::assertStringContainsString('One revision only: no change to list.', $kernel->output());
        self::assertSame(0, $kernel->call('revision:lock', ['--revision' => 'head']));
        self::assertStringContainsString('No revision enum configured: nothing to lock.', $kernel->output());
        self::assertFileDoesNotExist($this->temporary . '/lock');
    }

    private function jobsTable(): void
    {
        $config = $this->application()['config'];
        $config->set('queue.default', 'database');
        $config->set('queue.failed.driver', 'null');
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
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
