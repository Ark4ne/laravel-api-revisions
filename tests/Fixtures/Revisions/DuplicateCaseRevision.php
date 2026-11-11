<?php

declare(strict_types=1);

namespace Test\Fixtures\Revisions;

use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * An invalid `order()`: `First` is listed twice, so it has as many entries as cases but misses `Second`.
 */
enum DuplicateCaseRevision: string implements Revision
{
    use IsRevision;

    case First = 'first';
    case Second = 'second';

    public static function order(): array
    {
        return [self::First, self::First];
    }
}
