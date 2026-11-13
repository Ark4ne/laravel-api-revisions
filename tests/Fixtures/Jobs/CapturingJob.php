<?php

declare(strict_types=1);

namespace Test\Fixtures\Jobs;

use Ark4ne\ApiRevisions\Laravel\Queue\CapturesRevision;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;
use Test\Fixtures\ApiRevision;
use Throwable;

/**
 * A queued job marked `CapturesRevision`: it records the revision it runs in, then throws when asked to.
 */
final class CapturingJob implements ShouldQueue, CapturesRevision
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly bool $fails = false) {}

    public function handle(JobLog $log): void
    {
        $log->handled[] = ApiRevision::current();
        if ($this->fails) {
            throw new RuntimeException('The job failed.');
        }
    }

    public function failed(Throwable $e): void
    {
        Container::getInstance()->make(JobLog::class)->failed[] = ApiRevision::current();
    }
}
