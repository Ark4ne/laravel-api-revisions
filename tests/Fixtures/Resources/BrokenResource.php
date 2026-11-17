<?php

declare(strict_types=1);

namespace Test\Fixtures\Resources;

use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Test\Fixtures\Eloquent\Order;

/**
 * A plain value that is not null-safe: it fails on the empty subject of the compilation.
 *
 * @mixin Order
 */
final class BrokenResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'year' => $this->placed_at->format('Y'),
        ];
    }
}
