<?php

declare(strict_types=1);

namespace Test\Fixtures\Resources;

use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource;
use Ark4ne\ApiRevisions\Schema\ResourceSchema;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Nests a `NativeResource` (its native conditions decided on its own subject) and returns its declaration as a
 * `Collection`, as `JsonResource::toArray()` allows.
 */
final class WrapperResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    /**
     * @return Collection<string, ResourceSchema>
     */
    public function toArray(Request $request): Collection
    {
        return new Collection([
            'order' => Schema::resource(NativeResource::class),
        ]);
    }
}
