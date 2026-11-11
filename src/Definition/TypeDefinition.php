<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Definition;

/**
 * The type of a field at one revision: its kind, its constraints and its documentation. Public contract of the tools:
 * immutable, serializable, closure-free.
 */
final readonly class TypeDefinition
{
    /**
     * @param list<mixed> $examples JSON-ready values
     */
    private function __construct(
        public Kind $kind,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?string $format = null,
        public ?string $pattern = null,
        public ?string $description = null,
        public array $examples = [],
        public bool $deprecated = false,
    ) {}

    public static function of(Kind $kind): self
    {
        return new self($kind);
    }

    /**
     * The minimum: the length of a string, the size of a list, the value of a number.
     */
    public function withMin(int|float|null $min): self
    {
        return new self($this->kind, $min, $this->max, $this->format, $this->pattern, $this->description, $this->examples, $this->deprecated);
    }

    /**
     * The maximum: the length of a string, the size of a list, the value of a number.
     */
    public function withMax(int|float|null $max): self
    {
        return new self($this->kind, $this->min, $max, $this->format, $this->pattern, $this->description, $this->examples, $this->deprecated);
    }

    public function withFormat(?string $format): self
    {
        return new self($this->kind, $this->min, $this->max, $format, $this->pattern, $this->description, $this->examples, $this->deprecated);
    }

    /**
     * A regular expression (PCRE, with delimiters) the string matches.
     */
    public function withPattern(?string $pattern): self
    {
        return new self($this->kind, $this->min, $this->max, $this->format, $pattern, $this->description, $this->examples, $this->deprecated);
    }

    public function withDescription(?string $description): self
    {
        return new self($this->kind, $this->min, $this->max, $this->format, $this->pattern, $description, $this->examples, $this->deprecated);
    }

    /**
     * @param scalar|array<mixed>|null $example a JSON-ready value
     */
    public function addExample(mixed $example): self
    {
        return new self($this->kind, $this->min, $this->max, $this->format, $this->pattern, $this->description, [...$this->examples, $example], $this->deprecated);
    }

    public function withDeprecated(bool $deprecated): self
    {
        return new self($this->kind, $this->min, $this->max, $this->format, $this->pattern, $this->description, $this->examples, $deprecated);
    }
}
