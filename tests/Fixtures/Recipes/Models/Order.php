<?php

declare(strict_types=1);

namespace Test\Fixtures\Recipes\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Test\Fixtures\Recipes\Enums\OrderStatus;

/**
 * An order of the recipes, never saved: its attributes are filled and its relations set by the tests.
 *
 * @property string $title
 * @property OrderStatus $status
 * @property int $total_cents
 * @property string $first_name
 * @property string $last_name
 * @property list<string> $tags
 * @property list<array{sku: string, quantity: int}> $lines
 * @property Customer|null $customer
 */
final class Order extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_cents' => 'integer',
            'tags' => 'array',
            'lines' => 'array',
        ];
    }
}
