<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Definition;

/**
 * The way a value travels between revisions: `Down` from a newer shape to an older one (responses), `Up` from an
 * older shape to a newer one (requests). One direction per declaration kind (model.md D27).
 */
enum Direction: string
{
    case Down = 'down';
    case Up = 'up';
}
