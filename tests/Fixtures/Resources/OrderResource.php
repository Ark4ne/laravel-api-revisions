<?php

declare(strict_types=1);

namespace Test\Fixtures\Resources;

use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Eloquent\Order;
use Test\Fixtures\Enums\OrderStatus;

/**
 * The `OrderResource` of the reference scenario (§4.3, §6) on an Eloquent `Order`.
 *
 * @mixin Order
 *
 * @property Order $resource
 */
final class OrderResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title' => Schema::string(),
            'status' => Schema::enum(OrderStatus::class)
                ->was(ApiRevision::Winter25, name: 'state'),
            'total' => Schema::int(static fn(mixed $value, Order $order): int => $order->total_cents)
                ->was(
                    ApiRevision::Winter25,
                    schema: Schema::string(),
                    down: static fn(int $cents): string => number_format($cents / 100, 2, '.', ''),
                )
                ->was(ApiRevision::Genesis, schema: Schema::float()),
            'placed_at' => Schema::dateTime()
                ->was(ApiRevision::Winter25, schema: Schema::date(), down: static fn(DateTimeImmutable $at): DateTimeImmutable => $at->setTime(0, 0)),
            'customer' => Schema::resource(CustomerResource::class)->whenLoaded('customer'),
            ...Schema::since(ApiRevision::Atlas, [
                'tags' => Schema::list(Schema::string()),
                'notes' => Schema::string()->nullable(),
            ]),
            ...Schema::until(ApiRevision::Spring26, [
                'name' => Schema::string(fn(): string => $this->resource->fullName()),
            ]),
        ];
    }
}
