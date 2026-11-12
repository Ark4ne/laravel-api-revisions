<?php

declare(strict_types=1);

namespace Test\Fixtures\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Eloquent customer of an order, never saved.
 *
 * @property string $name
 */
final class Customer extends Model
{
    protected $guarded = [];

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
