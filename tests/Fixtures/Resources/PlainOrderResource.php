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
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Order;

/**
 * `OrderDeclaration` as a `RevisionedResource` on the plain `Order`: what `bin/benchmark.php` renders, with no Eloquent
 * to boot.
 *
 * @property Order $resource
 */
final class PlainOrderResource extends JsonResource implements Revisioned
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
            'total' => Schema::int()
                ->was(
                    ApiRevision::Winter25,
                    schema: Schema::string(),
                    down: static fn(int $cents): string => number_format($cents / 100, 2, '.', ''),
                )
                ->was(ApiRevision::Genesis, schema: Schema::float()),
            'placed_at' => Schema::dateTime()
                ->was(ApiRevision::Winter25, schema: Schema::date(), down: static fn(DateTimeImmutable $at): DateTimeImmutable => $at->setTime(0, 0)),
            'delivery' => Schema::object([
                'day' => Schema::date(),
            ]),
            'customer' => Schema::resource(CustomerDeclaration::class),
            ...Schema::since(ApiRevision::Atlas, [
                'tags' => Schema::list(Schema::string()),
                'notes' => Schema::string()->nullable(),
            ]),
            ...Schema::until(ApiRevision::Spring26, [
                'name' => Schema::string(static fn(mixed $value, Order $order): string => $order->fullName()),
            ]),
        ];
    }
}
