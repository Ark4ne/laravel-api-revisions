<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Queue;

use Ark4ne\ApiRevisions\Exceptions\UnknownRevisionException;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\RevisionValue;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use Closure;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\CallQueuedHandler;
use Throwable;

/**
 * The handler of a queued job marked `CapturesRevision` (D64): `capture()` (a payload hook registered by the provider)
 * stores the backed value of the revision of the dispatch in the payload and names this handler in place of
 * Laravel's `CallQueuedHandler`; `call()` and `failed()` then run Laravel's handler inside `using()` of that revision
 * (Execute Around: nothing outlives the job, the `sync` queue included). A stored value the enum no longer knows
 * throws `UnknownRevisionException` (the attempt fails); `failed()` then runs in the base revision.
 *
 * @internal
 */
final readonly class CapturedRevisionHandler
{
    /**
     * The payload key holding the backed value of the revision of the dispatch.
     */
    public const string KEY = 'ark4ne:revision';

    public function __construct(
        private CallQueuedHandler $handler,
        private RevisionsConfig $config,
        private RevisionScope $scope,
    ) {}

    /**
     * The entries a payload hook adds to the payload of `$payload['data']['command']`: none unless it is a job marked
     * `CapturesRevision` handled by Laravel's `CallQueuedHandler`; else the backed value of the revision `$current`
     * gives (called only then: a job not marked never reads the configuration) and this handler; none either when it
     * gives `null` (no enum configured, model.md D80).
     *
     * @param array<array-key, mixed> $payload
     * @param Closure(): ?Revision $current
     *
     * @return array<string, mixed>
     */
    public static function capture(array $payload, Closure $current): array
    {
        $data = $payload['data'] ?? null;
        if (($payload['job'] ?? null) !== CallQueuedHandler::class . '@call'
            || !is_array($data)
            || !($data['command'] ?? null) instanceof CapturesRevision) {
            return [];
        }

        $revision = $current();

        return $revision === null ? [] : [self::KEY => $revision->value, 'job' => self::class . '@call'];
    }

    /**
     * Runs the job of `$data` in the revision stored in the payload of `$job`.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws UnknownRevisionException when the stored revision is no longer a case of the enum (never without enum
     *                                   configured: the job runs in the implicit revision, model.md D80)
     */
    public function call(Job $job, array $data): void
    {
        // no enum configured any more (D80): a revision captured before runs in the one implicit revision
        $revision = $this->config->revisioned ? RevisionValue::parse($this->config->enum, $job->payload()[self::KEY] ?? null) : null;
        if ($revision === null) {
            $this->handler->call($job, $data);

            return;
        }
        $this->scope->using($revision, fn() => $this->handler->call($job, $data));
    }

    /**
     * Runs the `failed()` hook of the job of `$data` in the revision stored in the payload of `$job`, or in the base
     * revision when the enum no longer knows it.
     *
     * @param array<array-key, mixed> $data
     */
    public function failed(array $data, Throwable $e, string $uuid, ?Job $job = null): void
    {
        try {
            $revision = RevisionValue::parse($this->config->enum, $job?->payload()[self::KEY] ?? null);
        } catch (UnknownRevisionException) {
            $revision = null;
        }
        if ($revision === null) {
            $this->handler->failed($data, $e, $uuid, $job);

            return;
        }
        $this->scope->using($revision, fn() => $this->handler->failed($data, $e, $uuid, $job));
    }
}
