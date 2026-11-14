<?php

declare(strict_types=1);

namespace Test\Fixtures\Recipes;

use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Meta;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * The revisions of the recipes of the agent skill (skills/laravel-api-revisions/recipes.md).
 */
enum ApiRevision: string implements Revision
{
    use IsRevision;

    #[Meta('First published revision', publishedAt: '2025-06-01')]
    case Genesis = 'genesis';

    #[Meta('`paid` becomes `state`, totals become decimal strings', publishedAt: '2025-12-01', deprecatedAt: '2026-11-01')]
    case Winter25 = 'winter-25';

    #[Meta('`state` renamed `status`, totals in cents, `name` split, tags')]
    case Spring26 = 'spring-26';

    public static function order(): array
    {
        return [self::Genesis, self::Winter25, self::Spring26];
    }
}
