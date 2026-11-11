<?php

declare(strict_types=1);

namespace Test\Fixtures\Enums;

/**
 * A pure enum: no backed value to send or receive.
 */
enum Suit
{
    case Hearts;
    case Spades;
}
