<?php

declare(strict_types=1);

namespace Test\Fixtures;

/**
 * A plain subject of the fixtures: the customer of an order.
 */
final readonly class Customer
{
    public function __construct(
        public string $firstName,
        public string $lastName,
    ) {}
}
