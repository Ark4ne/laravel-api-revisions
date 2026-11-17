<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

/**
 * A declaration retiring the oldest revision makes dead, with what to do with it.
 */
enum PrunedDeclaration: string
{
    /**
     * `until(P)`: the field exists in `P` only.
     */
    case Until = 'until';

    /**
     * `was(P)`: the shape the field had in `P` only.
     */
    case Was = 'was';

    /**
     * `since(P)`: a bound already a no-op.
     */
    case Since = 'since';

    /**
     * What to delete once `P` is retired.
     */
    public function advice(): string
    {
        return match ($this) {
            self::Until => 'delete the field',
            self::Was => 'delete the was()',
            self::Since => 'delete the since(), already a no-op',
        };
    }
}
