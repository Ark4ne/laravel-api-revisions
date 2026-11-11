<?php

declare(strict_types=1);

namespace Test\Fixtures\Revisions;

use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Meta;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * Invalid `#[Meta]` declarations, one per case.
 */
enum InvalidMetaRevision: string implements Revision
{
    use IsRevision;

    /** February has no 30th. */
    #[Meta('Broken', publishedAt: '2026-02-30')]
    case BadDate = 'bad-date';

    /** `#[Meta]` is not repeatable. */
    #[Meta('One')]
    #[Meta('Two')] // @phpstan-ignore attribute.nonRepeatable
    case Repeated = 'repeated';

    /** A date must be a string. */
    #[Meta('Wrong type', publishedAt: 20260401)] // @phpstan-ignore argument.type
    case WrongType = 'wrong-type';

    public static function order(): array
    {
        return [self::BadDate, self::Repeated, self::WrongType];
    }
}
