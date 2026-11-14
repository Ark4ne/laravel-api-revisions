<?php

declare(strict_types=1);

namespace Test\Fixtures\Unrevisioned;

use Ark4ne\ApiRevisions\Laravel\Queue\CapturesRevision;
use Ark4ne\ApiRevisions\Revisions\Unrevisioned;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A queued job marked `CapturesRevision` in an application without revision enum: it records the revision it ran in.
 */
final class PostJob implements ShouldQueue, CapturesRevision
{
    use Dispatchable;
    use Queueable;

    /**
     * @var list<Unrevisioned>
     */
    public static array $ran = [];

    public function handle(): void
    {
        self::$ran[] = Unrevisioned::current();
    }
}
