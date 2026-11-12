<?php

declare(strict_types=1);

namespace Test\Fixtures;

use Ark4ne\ApiRevisions\Conversion\Converter;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Schema\DeclaresConversions;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\InvalidValue;

/**
 * A custom type (model.md §3): an amount in cents and a currency, `{"amount": 1250, "currency": "EUR"}`. Accepts a
 * `Money` or an array with an int `amount` and a 3-letter `currency`. Converts to and from a float in both directions
 * (`MoneyToFloat`, `FloatToMoney` in euros).
 *
 * @extends Descriptor<Money>
 */
final class MoneySchema extends Descriptor implements DeclaresConversions
{
    public static function conversions(Converters $converters): Converters
    {
        foreach (Direction::cases() as $direction) {
            $converters = $converters
                ->register('money', Kind::Float, $direction, static fn(): Converter => new MoneyToFloat())
                ->register(Kind::Float, 'money', $direction, static fn(): Converter => new FloatToMoney('EUR'));
        }

        return $converters;
    }

    /**
     * @param non-empty-string|null $currency the currency assumed when the value has none
     */
    public function __construct(mixed $source = null, private readonly ?string $currency = null)
    {
        parent::__construct($source);
    }

    public function declaredRules(): array
    {
        return ['array', 'required_array_keys:amount,currency', ...parent::declaredRules()];
    }

    public function normalize(mixed $value): mixed
    {
        if ($value instanceof Money) {
            return $value;
        }
        $amount = is_array($value) ? $value['amount'] ?? null : null;
        $currency = is_array($value) ? $value['currency'] ?? $this->currency : null;

        return is_int($amount) && is_string($currency) && preg_match('/^[A-Z]{3}$/D', $currency) === 1
            ? new Money($amount, $currency)
            : new InvalidValue($value, Kind::Custom, null);
    }

    public function serialize(mixed $value): mixed
    {
        return $value instanceof Money ? ['amount' => $value->amount, 'currency' => $value->currency] : $value;
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::custom('money', [
            'type' => 'object',
            'properties' => ['amount' => ['type' => 'integer'], 'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$']],
            'required' => ['amount', 'currency'],
        ]);
    }
}
