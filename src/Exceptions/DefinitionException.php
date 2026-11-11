<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use BackedEnum;
use LogicException;

/**
 * A programming error in a declaration. Named constructors only.
 */
final class DefinitionException extends LogicException implements RevisionsException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function mixedRevisions(BackedEnum $a, BackedEnum $b): self
    {
        return new self(sprintf(
            'Cannot compare revisions of two enums: %s::%s and %s::%s.',
            $a::class,
            $a->name,
            $b::class,
            $b->name,
        ));
    }

    /**
     * @param class-string $class the revision enum whose `order()` is invalid
     * @param string $reason what is wrong, e.g. "Atlas is missing"
     */
    public static function invalidOrder(string $class, string $reason): self
    {
        return new self(sprintf('%s::order() must list every case exactly once: %s.', $class, $reason));
    }
}
