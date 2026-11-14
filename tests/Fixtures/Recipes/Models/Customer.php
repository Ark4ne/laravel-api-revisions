<?php

declare(strict_types=1);

namespace Test\Fixtures\Recipes\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer of the recipes, never saved.
 *
 * @property string $name
 * @property string $email
 */
final class Customer extends Model
{
    protected $guarded = [];
}
