<?php

declare(strict_types=1);

namespace Test\Fixtures\Jobs;

use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * The revisions the fixture jobs saw when they ran (bound as an instance by the test).
 */
final class JobLog
{
    /**
     * @var list<Revision>
     */
    public array $handled = [];

    /**
     * @var list<Revision>
     */
    public array $failed = [];
}
