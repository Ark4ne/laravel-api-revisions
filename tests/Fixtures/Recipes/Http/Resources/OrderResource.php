<?php

declare(strict_types=1);

namespace Test\Fixtures\Recipes\Http\Resources;

use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Test\Fixtures\Recipes\ApiRevision;
use Test\Fixtures\Recipes\Enums\OrderStatus;
use Test\Fixtures\Recipes\Models\Order;

/**
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
                ->was(ApiRevision::Winter25, name: 'state')
                ->was(
                    ApiRevision::Genesis,
                    name: 'paid',
                    schema: Schema::bool(),
                    down: fn(OrderStatus $status): bool => $status === OrderStatus::Paid,
                ),
            'total' => Schema::int('total_cents')
                ->was(
                    ApiRevision::Winter25,
                    schema: Schema::string(),
                    down: fn(int $cents): string => number_format($cents / 100, 2, '.', ''),
                )
                ->was(
                    ApiRevision::Genesis,
                    schema: Schema::float(),
                    down: fn(string $amount): float => (float) $amount,
                ),
            'lines' => Schema::list(Schema::object([
                'sku' => Schema::string(),
                'quantity' => Schema::int()->was(ApiRevision::Genesis, name: 'qty'),
            ])),
            'customer' => Schema::resource(CustomerResource::class)->whenLoaded('customer'),

            ...Schema::since(ApiRevision::Spring26, [
                'first_name' => Schema::string(),
                'last_name' => Schema::string(),
                'tags' => Schema::list(Schema::string()),
            ]),
            ...Schema::until(ApiRevision::Winter25, [
                'name' => Schema::string(fn($value, Order $order): string => "{$order->first_name} {$order->last_name}"),
            ]),
        ];
    }
}
