<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Lifetime;

/**
 * Optional contract of a `Revisioned` class that does not exist at every revision (model.md D76, D81): a resource type
 * added or retired. A class without it exists at every revision.
 */
interface HasLifetime
{
    /**
     * The revisions the class exists in: `Lifetime::since(R)`, `Lifetime::until(R)` or both, inclusive.
     */
    public static function revisionedLifetime(): Lifetime;
}
