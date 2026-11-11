<?php

declare(strict_types=1);

namespace Test\Fixtures;

use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * The revisions of the reference scenario (docs/specs/model.md §2). Cases are declared out of order on purpose:
 * `order()` is the only authority.
 */
enum ApiRevision: string implements Revision
{
    use IsRevision;

    case Atlas = 'atlas';
    case Genesis = 'genesis';
    case Spring26 = 'spring-26';
    case Winter25 = 'winter-25';

    public static function order(): array
    {
        return [self::Genesis, self::Winter25, self::Spring26, self::Atlas];
    }
}
