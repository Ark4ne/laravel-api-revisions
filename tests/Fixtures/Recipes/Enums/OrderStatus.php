<?php

declare(strict_types=1);

namespace Test\Fixtures\Recipes\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
