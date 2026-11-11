<?php

declare(strict_types=1);

namespace Test\Fixtures\Revisions;

use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * An invalid `order()`: it lists a case of another enum.
 */
enum ForeignCaseRevision: string implements Revision
{
    use IsRevision;

    case First = 'first';

    public static function order(): array
    {
        return [self::First, IntRevision::First]; // @phpstan-ignore return.type
    }
}
