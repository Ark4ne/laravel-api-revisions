<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use BackedEnum;
use LogicException;
use Throwable;

/**
 * A programming error in a declaration. Named constructors only.
 */
final class DefinitionException extends LogicException implements RevisionsException
{
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function mixedRevisions(BackedEnum $a, BackedEnum $b): self
    {
        return new self(sprintf(
            'Revisions of two enums cannot be mixed: %s::%s and %s::%s.',
            $a::class,
            $a->name,
            $b::class,
            $b->name,
        ));
    }

    /**
     * A revision of another enum where a case of `$expected` is required (`ApiRevision::using(OtherRevision::X)`).
     *
     * @param class-string $expected the revision enum required
     */
    public static function foreignRevision(BackedEnum $revision, string $expected): self
    {
        return new self(sprintf('%s::%s is not a revision of %s.', $revision::class, $revision->name, $expected));
    }

    /**
     * @param class-string $class the revision enum whose `order()` is invalid
     * @param string $reason what is wrong, e.g. "Atlas is missing"
     */
    public static function invalidOrder(string $class, string $reason): self
    {
        return new self(sprintf('%s::order() must list every case exactly once: %s.', $class, $reason));
    }

    /**
     * @param string $argument the `#[Meta]` argument, e.g. `publishedAt`
     */
    public static function invalidMetaDate(string $argument, string $value): self
    {
        return new self(sprintf("%s must be a Y-m-d date, got '%s'.", $argument, $value));
    }

    /**
     * An invalid `#[Meta]` on a revision case (a bad date, a repeated attribute, a wrong argument); `$previous`
     * says why.
     */
    public static function invalidMeta(BackedEnum $case, Throwable $previous): self
    {
        return new self(sprintf('Invalid #[Meta] on %s::%s: %s', $case::class, $case->name, $previous->getMessage()), $previous);
    }
}
