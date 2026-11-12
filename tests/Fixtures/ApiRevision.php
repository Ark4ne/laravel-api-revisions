<?php

declare(strict_types=1);

namespace Test\Fixtures;

use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Meta;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * The revisions of the reference scenario (docs/specs/model.md §2). Cases are declared out of order on purpose:
 * `order()` is the only authority.
 */
enum ApiRevision: string implements Revision
{
    use IsRevision;

    #[Meta('Tags and notes; `name` replaced by `first_name` / `last_name`')]
    case Atlas = 'atlas';

    #[Meta('First published revision', publishedAt: '2025-06-01')]
    case Genesis = 'genesis';

    #[Meta('Totals in cents, `state` renamed `status`', publishedAt: '2026-04-01', deprecatedAt: '2026-11-01', sunsetAt: '2027-05-01')]
    case Spring26 = 'spring-26';

    #[Meta('Totals become decimal strings', publishedAt: '2025-12-01')]
    case Winter25 = 'winter-25';

    public static function order(): array
    {
        return [self::Genesis, self::Winter25, self::Spring26, self::Atlas];
    }
}
