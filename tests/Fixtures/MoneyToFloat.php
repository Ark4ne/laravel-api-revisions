<?php

declare(strict_types=1);

namespace Test\Fixtures;

use Ark4ne\ApiRevisions\Conversion\Converter;

/**
 * money → float: the amount in units (`Money(1250, 'EUR')` → `12.5`), the currency dropped. A conversion of the
 * custom type `MoneySchema`.
 */
final class MoneyToFloat implements Converter
{
    public function convert(mixed $value, mixed $subject = null): mixed
    {
        return $value instanceof Money ? $value->amount / 100 : $value;
    }

    public function lossless(): bool
    {
        return false;
    }

    public function extraRules(): array
    {
        return [];
    }
}
