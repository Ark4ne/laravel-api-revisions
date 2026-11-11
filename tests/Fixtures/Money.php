<?php

declare(strict_types=1);

namespace Test\Fixtures;

/**
 * An amount in cents and its currency: the value of the custom type `MoneySchema`.
 */
final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {}
}
