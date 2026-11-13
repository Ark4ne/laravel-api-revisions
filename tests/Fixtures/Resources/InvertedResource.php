<?php

declare(strict_types=1);

namespace Test\Fixtures\Resources;

use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Test\Fixtures\ApiRevision;

/**
 * A resource declaring an inverted lifetime: its `DefinitionException` reaches the check with its code.
 */
final class InvertedResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total' => Schema::int()->since(ApiRevision::Atlas)->until(ApiRevision::Genesis),
        ];
    }
}
