<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Direction;

/**
 * What a declaration of a `Revisioned` class describes: a response (projected down), or the body or the query of a
 * request (validated and upgraded up). One class may declare several (a `FormRequest`: body and query, model.md D48).
 */
enum DeclarationKind: string
{
    case Response = 'response';
    case RequestBody = 'request-body';
    case RequestQuery = 'request-query';

    /**
     * The one direction its shapes plan (model.md D27): down for a response, up for a request.
     */
    public function direction(): Direction
    {
        return $this === self::Response ? Direction::Down : Direction::Up;
    }
}
