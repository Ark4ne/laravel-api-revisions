<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Revisions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Support\Memo;
use Error;
use ReflectionEnumBackedCase;

/**
 * Reads the `#[Meta]` of a revision case by reflection, memoized per case (`Support\Memo`). Applications call
 * `Revision::meta()`.
 *
 * @internal
 */
final class MetaReader
{
    /**
     * The `Meta` of the case, an empty one when it declares none. An invalid `Meta` throws
     * `DefinitionException::invalidMeta()`, naming the case: a bad date, but also a repeated attribute or a wrong
     * argument (PHP `Error`s raised when the attribute is built).
     */
    public static function of(Revision $revision): Meta
    {
        return Memo::remember(
            sprintf('revision-meta:%s::%s', $revision::class, $revision->name),
            static function () use ($revision): Meta {
                $attributes = (new ReflectionEnumBackedCase($revision, $revision->name))->getAttributes(Meta::class);

                try {
                    return isset($attributes[0]) ? $attributes[0]->newInstance() : new Meta();
                } catch (DefinitionException|Error $exception) {
                    throw DefinitionException::invalidMeta($revision, $exception);
                }
            },
        );
    }
}
