<?php

declare(strict_types=1);

namespace Test\Fixtures\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
}
