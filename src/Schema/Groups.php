<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Ark4ne\ApiRevisions\Support\Undefined;
use Closure;

/**
 * Applies a group's `since` or `until` to its members (§4.4 rule 1), behind `Schema::since()` /
 * `Schema::until()`. A member's own narrower bound and own default win; a wider one throws.
 *
 * @internal
 */
final class Groups
{
    /**
     * @param array<string, Descriptor<mixed>> $fields
     *
     * @return array<string, Descriptor<mixed>>
     */
    public static function since(Revision $revision, array $fields, mixed $default): array
    {
        return self::apply($fields, static function (string $key, Descriptor $member) use ($revision, $default): Descriptor {
            $own = $member->lifetime()->since;
            if ($own !== null && RevisionOrder::compare($own, $revision) < 0) {
                throw DefinitionException::sinceOlderThanGroup($key, $own, $revision);
            }

            return $member->inGroup(
                $member->lifetime()->withSince($own ?? $revision),
                Undefined::is($member->default()) ? $default : $member->default(),
            );
        });
    }

    /**
     * @param array<string, Descriptor<mixed>> $fields
     *
     * @return array<string, Descriptor<mixed>>
     */
    public static function until(Revision $revision, array $fields): array
    {
        return self::apply($fields, static function (string $key, Descriptor $member) use ($revision): Descriptor {
            $own = $member->lifetime()->until;
            if ($own !== null && RevisionOrder::compare($own, $revision) > 0) {
                throw DefinitionException::untilNewerThanGroup($key, $own, $revision);
            }

            return $member->inGroup($member->lifetime()->withUntil($own ?? $revision), $member->default());
        });
    }

    /**
     * @param array<string, Descriptor<mixed>> $fields
     * @param Closure(string, Descriptor<mixed>): Descriptor<mixed> $bound
     *
     * @return array<string, Descriptor<mixed>>
     */
    private static function apply(array $fields, Closure $bound): array
    {
        $grouped = [];
        foreach ($fields as $key => $member) {
            // @phpstan-ignore function.alreadyNarrowedType (checked at run time: declarations are application code)
            if (!is_string($key) || $key === '') {
                throw DefinitionException::invalidGroupKey($key);
            }
            // @phpstan-ignore instanceof.alwaysTrue (checked at run time: declarations are application code)
            if (!$member instanceof Descriptor) {
                throw DefinitionException::invalidGroupMember($key, get_debug_type($member));
            }
            $grouped[$key] = $bound($key, $member);
        }

        return $grouped;
    }
}
