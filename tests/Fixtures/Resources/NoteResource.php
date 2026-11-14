<?php

declare(strict_types=1);

namespace Test\Fixtures\Resources;

use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Test\Fixtures\Eloquent\Order;

/**
 * A resource rendering nothing for a subject without notes, although its shape requires them: an empty rendering the
 * contract test must reject.
 *
 * @mixin Order
 */
final class NoteResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof Order && $this->notes === null) {
            return [];
        }

        return [
            'notes' => Schema::string(),
        ];
    }
}
