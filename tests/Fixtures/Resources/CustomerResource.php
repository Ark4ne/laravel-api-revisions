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
 * The customer of an `OrderResource`, nested with its own history; its orders back when loaded (a cycle).
 */
final class CustomerResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => Schema::string()->was(ApiRevision::Winter25, name: 'full_name'),
            'orders' => Schema::list(Schema::resource(OrderResource::class))
                ->since(ApiRevision::Spring26)
                ->whenLoaded('orders'),
        ];
    }
}
