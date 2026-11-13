<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Laravel\Queue\CapturedRevisionHandler;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Jobs\CapturingJob;
use Test\Fixtures\Jobs\JobLog;
use Test\Fixtures\Jobs\PlainJob;

final class CapturesRevisionTest extends FeatureTestCase
{
    private JobLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.default', 'oldest');
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
        $this->log = new JobLog();
        $this->application()->instance(JobLog::class, $this->log);
    }

    public function test_a_job_runs_in_the_revision_of_its_dispatch(): void
    {
        RevisionScope::get()->using(ApiRevision::Winter25, static function (): void {
            CapturingJob::dispatch();
        });

        $this->work();

        self::assertSame([ApiRevision::Winter25], $this->log->handled);
    }

    public function test_a_job_dispatched_by_a_request_runs_in_the_revision_of_the_request(): void
    {
        $this->application()->make(Router::class)->post('/orders', static function (): array {
            CapturingJob::dispatch();

            return [];
        })->middleware('revision');

        $this->postJson('/orders', [], ['Api-Revision' => 'winter-25'])->assertOk();
        $this->work();

        self::assertSame([ApiRevision::Winter25], $this->log->handled);
    }

    public function test_a_chained_job_runs_in_the_revision_of_the_job_dispatching_it(): void
    {
        RevisionScope::get()->using(ApiRevision::Spring26, static function (): void {
            Bus::chain([new CapturingJob(), new CapturingJob()])->dispatch();
        });

        $this->work();
        $this->work();

        self::assertSame([ApiRevision::Spring26, ApiRevision::Spring26], $this->log->handled);
    }

    public function test_a_job_not_marked_runs_in_the_base_revision(): void
    {
        RevisionScope::get()->using(ApiRevision::Winter25, static function (): void {
            PlainJob::dispatch();
        });

        $this->work();

        self::assertSame([ApiRevision::Genesis], $this->log->handled);
    }

    public function test_the_failed_hook_runs_in_the_revision_of_the_dispatch(): void
    {
        RevisionScope::get()->using(ApiRevision::Spring26, static function (): void {
            CapturingJob::dispatch(fails: true);
        });

        $this->work();

        self::assertSame([ApiRevision::Spring26], $this->log->handled);
        self::assertSame([ApiRevision::Spring26], $this->log->failed);
    }

    public function test_a_job_whose_payload_holds_no_revision_runs_in_the_base_revision(): void
    {
        RevisionScope::get()->using(ApiRevision::Winter25, static function (): void {
            CapturingJob::dispatch();
        });
        $this->rewritePayload(static function (array $payload): array {
            unset($payload[CapturedRevisionHandler::KEY]);

            return $payload;
        });

        $this->work();

        self::assertSame([ApiRevision::Genesis], $this->log->handled);
    }

    public function test_a_job_whose_revision_the_enum_no_longer_knows_fails_and_its_failed_hook_runs_in_the_base_revision(): void
    {
        RevisionScope::get()->using(ApiRevision::Winter25, static function (): void {
            CapturingJob::dispatch();
        });
        $this->rewritePayload(static fn(array $payload): array => [...$payload, CapturedRevisionHandler::KEY => 'pruned']);

        $this->work();

        self::assertSame([], $this->log->handled);
        self::assertSame([ApiRevision::Genesis], $this->log->failed);
    }

    public function test_a_sync_job_runs_in_the_revision_of_its_dispatch_and_leaves_the_scope_as_it_was(): void
    {
        $this->application()['config']->set('queue.default', 'sync');

        RevisionScope::get()->using(ApiRevision::Winter25, static function (): void {
            CapturingJob::dispatch();
        });

        self::assertSame([ApiRevision::Winter25], $this->log->handled);
        self::assertSame(ApiRevision::Genesis, ApiRevision::current());
    }

    public function test_the_scope_of_the_worker_is_left_as_it_was_after_a_job(): void
    {
        RevisionScope::get()->using(ApiRevision::Winter25, static function (): void {
            CapturingJob::dispatch();
        });

        $this->work();

        self::assertSame(ApiRevision::Genesis, ApiRevision::current());
    }

    private function work(): void
    {
        $command = $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);
        self::assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful();
    }

    /**
     * @param Closure(array<array-key, mixed>): array<array-key, mixed> $rewrite
     */
    private function rewritePayload(Closure $rewrite): void
    {
        $stored = DB::table('jobs')->value('payload');
        self::assertIsString($stored);
        $payload = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        DB::table('jobs')->update(['payload' => json_encode($rewrite($payload), JSON_THROW_ON_ERROR)]);
    }
}
