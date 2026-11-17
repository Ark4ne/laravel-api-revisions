<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * The lifetime of a class: the one its `HasLifetime` gives, every revision otherwise. What the compiler,
 * the check, the cache, the export, the lock and the changelog read; what an application or a consumer reads to answer
 * 404 to a client of a revision the class does not exist in.
 */
final class ClassLifetime
{
    private function __construct() {}

    /**
     * @param class-string $class
     */
    public static function of(string $class): Lifetime
    {
        return is_subclass_of($class, HasLifetime::class) ? $class::revisionedLifetime() : Lifetime::always();
    }

    /**
     * Whether `$class` exists at `$revision`; `DefinitionException::mixedRevisions()` for a lifetime of another enum.
     *
     * @param class-string $class
     */
    public static function exists(string $class, Revision $revision): bool
    {
        return self::of($class)->covers($revision);
    }
}
