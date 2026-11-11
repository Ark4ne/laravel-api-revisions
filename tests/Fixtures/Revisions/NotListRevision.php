<?php

declare(strict_types=1);

namespace Test\Fixtures\Revisions;

use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * An invalid `order()`: every case once, but keyed out of sequence, so the head would be ambiguous.
 */
enum NotListRevision: string implements Revision
{
    use IsRevision;

    case First = 'first';
    case Second = 'second';

    public static function order(): array
    {
        return [1 => self::Second, 0 => self::First]; // @phpstan-ignore return.type
    }
}
