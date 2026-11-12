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
 * Closures of `toArray()` reaching the model three ways (model.md §6.6), and the peeked `$value` of a source closure.
 *
 * @mixin Order
 *
 * @property Order $resource
 */
final class ReachingResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'through_resource' => Schema::string(fn(): string => $this->resource->title),
            'delegated' => Schema::string(fn(): string => $this->title),
            'subject' => Schema::string(static fn(mixed $value, Order $order): string => $order->title),
            'title' => Schema::string(static fn(?string $value): string => "peeked {$value}"),
            'customer' => Schema::string(static fn(mixed $value): string => get_debug_type($value))->nullable(),
            'asked' => $request->query('asked'),
            'flag' => $this->when($request->has('flag'), 'on'),
        ];
    }
}
