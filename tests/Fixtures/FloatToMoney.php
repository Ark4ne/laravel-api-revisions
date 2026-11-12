<?php

declare(strict_types=1);

namespace Test\Fixtures;

use Ark4ne\ApiRevisions\Conversion\Converter;

/**
 * float → money: an amount in units read as cents in a fixed currency (`12.5` → `Money(1250, 'EUR')`). A conversion
 * of the custom type `MoneySchema`.
 */
final readonly class FloatToMoney implements Converter
{
    public function __construct(private string $currency) {}

    public function convert(mixed $value, mixed $subject = null): mixed
    {
        return is_float($value) ? new Money((int) round($value * 100), $this->currency) : $value;
    }

    public function lossless(): bool
    {
        return false;
    }

    public function extraRules(): array
    {
        return ['numeric'];
    }
}
