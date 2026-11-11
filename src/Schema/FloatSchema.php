<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;

/**
 * A float: accepts ints, floats and numeric strings without surrounding blanks, finite only (JSON has no NaN nor
 * infinity) (model.md §6.3, Q12). An int beyond 2^53 loses precision, as in PHP.
 *
 * @extends Descriptor<float>
 */
final class FloatSchema extends Descriptor
{
    private int|float|null $min = null;

    private int|float|null $max = null;

    public function min(int|float $min): self
    {
        $clone = clone $this;
        $clone->min = $min;

        return $clone;
    }

    public function max(int|float $max): self
    {
        $clone = clone $this;
        $clone->max = $max;

        return $clone;
    }

    public function normalize(mixed $value): mixed
    {
        $float = match (true) {
            is_int($value), is_float($value), is_string($value) && is_numeric($value) && trim($value) === $value => (float) $value,
            default => null,
        };
        if ($float !== null && is_finite($float)) {
            return $float;
        }

        return new InvalidValue($value, Kind::Float, is_scalar($value) && $float === null ? (float) $value : null);
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::of(Kind::Float)->withMin($this->min)->withMax($this->max);
    }
}
