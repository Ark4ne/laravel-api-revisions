<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;

/**
 * An integer: accepts ints, integral floats within the int range, and integral numeric strings: digits (`'-007'`,
 * exact up to the int range) or any other numeric string with an integral value exact as a float (`'12.00'`, `'1e3'`,
 * up to 2^53). Never surrounding blanks (model.md §6.3, D32).
 *
 * @extends Descriptor<int>
 */
final class IntSchema extends Descriptor
{
    /**
     * 2^53: beyond, a float no longer holds every integer.
     */
    private const EXACT = 9_007_199_254_740_992;

    private ?int $min = null;

    private ?int $max = null;

    public function min(int $min): self
    {
        $clone = clone $this;
        $clone->min = $min;

        return $clone;
    }

    public function max(int $max): self
    {
        $clone = clone $this;
        $clone->max = $max;

        return $clone;
    }

    public function normalize(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value;
        }
        // -2^63 is exact as a float, 2^63 (the float PHP_INT_MAX rounds to) is already out of range.
        if (is_float($value) && floor($value) === $value && $value >= PHP_INT_MIN && $value < PHP_INT_MAX) {
            return (int) $value;
        }
        if (is_string($value) && ($int = self::parse($value)) !== null) {
            return $int;
        }

        return new InvalidValue($value, Kind::Int, is_scalar($value) && !self::outOfRange($value) ? (int) $value : null);
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::of(Kind::Int)->withMin($this->min)->withMax($this->max);
    }

    /**
     * The int of an integral numeric string, `null` when it is not one, overflows or is not exact.
     */
    private static function parse(string $value): ?int
    {
        if (preg_match('/\A(-?)0*(\d+)\z/', $value, $matches) === 1) {
            $canonical = ($matches[2] === '0' ? '' : $matches[1]) . $matches[2];
            $int = (int) $value;

            return (string) $int === $canonical ? $int : null;
        }

        if (!is_numeric($value) || trim($value) !== $value) {
            return null;
        }
        $float = (float) $value;

        return floor($float) === $float && abs($float) <= self::EXACT ? (int) $float : null;
    }

    private static function outOfRange(bool|int|float|string $value): bool
    {
        return is_float($value) && !($value >= PHP_INT_MIN && $value < PHP_INT_MAX);
    }
}
