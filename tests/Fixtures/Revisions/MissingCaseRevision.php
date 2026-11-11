<?php

declare(strict_types=1);

namespace Test\Fixtures\Revisions;

use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * An invalid `order()`: `Second` is missing.
 */
enum MissingCaseRevision: string implements Revision
{
    use IsRevision;

    case First = 'first';
    case Second = 'second';
    case Third = 'third';

    public static function order(): array
    {
        return [self::First, self::Third];
    }
}
