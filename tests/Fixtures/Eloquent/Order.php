<?php

declare(strict_types=1);

namespace Test\Fixtures\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Test\Fixtures\Enums\OrderStatus;

/**
 * An Eloquent order of the reference scenario, never saved: its attributes are filled and its relations set by the
 * tests.
 *
 * @property string $title
 * @property OrderStatus $status
 * @property int $total_cents
 * @property DateTimeImmutable $placed_at
 * @property list<string> $tags
 * @property string|null $notes
 * @property bool $visible
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

    public function fullName(): string
    {
        return $this->customer === null ? 'anonymous' : $this->customer->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_cents' => 'integer',
            'placed_at' => 'immutable_datetime',
            'tags' => 'array',
            'visible' => 'boolean',
        ];
    }
}
