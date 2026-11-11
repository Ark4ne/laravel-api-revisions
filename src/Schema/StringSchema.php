<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Support\Floats;
use Stringable;

/**
 * A string: accepts strings, ints, finite floats (written without precision loss) and `Stringable` objects
 * (model.md §6.3).
 *
 * @extends Descriptor<string>
 */
final class StringSchema extends Descriptor
{
    private ?int $min = null;

    private ?int $max = null;

    private ?string $format = null;

    private ?string $pattern = null;

    /**
     * Minimum length.
     */
    public function min(int $length): self
    {
        $clone = clone $this;
        $clone->min = $length;

        return $clone;
    }

    /**
     * Maximum length.
     */
    public function max(int $length): self
    {
        $clone = clone $this;
        $clone->max = $length;

        return $clone;
    }

    /**
     * A JSON Schema format (`email`, `uri`, `uuid`…).
     */
    public function format(string $format): self
    {
        $clone = clone $this;
        $clone->format = $format;

        return $clone;
    }

    /**
     * A regular expression (PCRE, with delimiters) the value matches.
     */
    public function pattern(string $pattern): self
    {
        $clone = clone $this;
        $clone->pattern = $pattern;

        return $clone;
    }

    public function normalize(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => $value,
            is_float($value) && is_finite($value) => Floats::toString($value),
            is_int($value), $value instanceof Stringable => (string) $value,
            default => new InvalidValue($value, Kind::String, is_scalar($value) && !is_float($value) ? (string) $value : null),
        };
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::of(Kind::String)
            ->withMin($this->min)
            ->withMax($this->max)
            ->withFormat($this->format)
            ->withPattern($this->pattern);
    }
}
