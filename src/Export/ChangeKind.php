<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Export;

/**
 * What a revision changes on one field.
 */
enum ChangeKind: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Renamed = 'renamed';
    case TypeChanged = 'type-changed';
}
