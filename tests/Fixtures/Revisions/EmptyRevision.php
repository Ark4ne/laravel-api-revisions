<?php

declare(strict_types=1);

namespace Test\Fixtures\Revisions;

use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * An invalid revision enum: no case, so no head.
 */
enum EmptyRevision: string implements Revision
{
    use IsRevision;

    public static function order(): array
    {
        return [];
    }
}
