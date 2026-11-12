<?php

declare(strict_types=1);

namespace Test\Fixtures;

use DateTimeImmutable;
use Test\Fixtures\Enums\OrderStatus;

/**
 * A plain subject of the fixtures: an order of the reference scenario, read by `OrderDeclaration`.
 */
final readonly class Order
{
    /**
     * @param array{name: string, orders: list<Order>} $customer the subject of the nested `CustomerDeclaration`
     * @param list<string> $tags
     */
    public function __construct(
        public string $title,
        public OrderStatus $status,
        public int $total,
        public DateTimeImmutable $placed_at,
        public DateTimeImmutable $day,
        public array $customer,
        public array $tags,
        public ?string $notes,
        public Customer $buyer,
    ) {}

    public function fullName(): string
    {
        return "{$this->buyer->firstName} {$this->buyer->lastName}";
    }
}
