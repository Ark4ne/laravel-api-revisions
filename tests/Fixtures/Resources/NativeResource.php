<?php

declare(strict_types=1);

namespace Test\Fixtures\Resources;

use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Eloquent\Order;

/**
 * A resource mixing descriptors with what a plain `JsonResource` declares: eager values (`eager-value`) and native
 * conditions (`native-condition`), honoured at run time.
 *
 * @mixin Order
 */
final class NativeResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title' => Schema::string(),
            'label' => $this->title,
            'notes' => $this->notes,
            'labels' => new Collection($this->tags ?? []),
            'secret' => $this->when($this->visible === true, 'shown'),
            $this->mergeWhen($this->visible === true, ['merged' => 'yes']),
            'buyer' => $this->whenLoaded('customer'),
            'buyer_resource' => new CustomerResource($this->whenLoaded('customer')),
            'regular' => $this->when($this->visible !== true, 'regular'),
            ...($this->visible === false ? [] : ['shown_only' => 'x']),
            'nested' => ['kept' => 1, 'dropped' => $this->when(false, 2)],
            'old' => Schema::string(static fn(): string => 'old')->until(ApiRevision::Winter25),
            'any' => Schema::rule([], static fn(): Collection => new Collection(['a' => 1])),
        ];
    }
}
