<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Http\Resolvers;

use Ark4ne\ApiRevisions\Exceptions\UnknownRevisionException;
use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * The revision named by a value a client sent (model.md D57): `null` or an empty string is no revision, a case of the
 * enum itself, a backed value its case (compared as a string, so an int-backed enum reads the digits of a string);
 * anything else throws `UnknownRevisionException` with the accepted values. Shared by the resolvers of the package.
 *
 * @internal
 */
final class RevisionValue
{
    /**
     * @param class-string<Revision> $enum
     */
    public static function parse(string $enum, mixed $value): ?Revision
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof $enum) {
            return $value;
        }
        $accepted = [];
        foreach ($enum::order() as $revision) {
            $accepted[] = (string) $revision->value;
            if ((is_string($value) || is_int($value)) && (string) $revision->value === (string) $value) {
                return $revision;
            }
        }

        throw UnknownRevisionException::unknown(is_string($value) || is_int($value) ? (string) $value : get_debug_type($value), $accepted);
    }
}
