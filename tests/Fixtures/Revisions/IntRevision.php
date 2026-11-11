<?php

declare(strict_types=1);

namespace Test\Fixtures\Revisions;

use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * An int-backed revision enum, whose backed values do not follow the order (spike finding F4).
 */
enum IntRevision: int implements Revision
{
    use IsRevision;

    case Third = 1;
    case First = 20;
    case Second = 3;

    public static function order(): array
    {
        return [self::First, self::Second, self::Third];
    }
}
