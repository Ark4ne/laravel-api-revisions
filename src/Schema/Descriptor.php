<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;

/**
 * A field at the head: its lazy source, its type and its modifiers. Immutable: every modifier returns a new instance.
 * The extension base of custom types (docs/architecture.md §16).
 *
 * @template TValue the PHP value of the type, once normalized
 */
abstract class Descriptor
{
    private readonly Source $source;

    private bool $nullable = false;

    private bool $required = false;

    private bool $sometimes = false;

    private ?string $description = null;

    /**
     * @var list<scalar|array<mixed>|null>
     */
    private array $examples = [];

    private bool $deprecated = false;

    /**
     * @var list<mixed>
     */
    private array $rules = [];

    /**
     * Not final: composite and custom descriptors take their own arguments first (`ListSchema($items, $source)`) and
     * call `parent::__construct($source)`, the only way to set the source.
     *
     * @param mixed $source `null` (the declared key), a key or dotted path, or a closure `($value, $subject)`; never an
     *                      evaluated value (`DefinitionException::eagerSource()`)
     */
    public function __construct(mixed $source = null)
    {
        $this->source = Source::from($source);
    }

    /**
     * The PHP value of the type from what the source read (model.md §6.3), or the refusal: never throws. `null` never
     * reaches it (the pipeline handles it first); given anyway, it is refused.
     *
     * @return TValue|InvalidValue
     */
    abstract public function normalize(mixed $value): mixed;

    /**
     * The JSON-ready value of a normalized value. Scalars serialize as they are.
     *
     * @param TValue $value
     */
    public function serialize(mixed $value): mixed
    {
        return $value;
    }

    /**
     * The type with its documentation (`describe()`, `example()`, `deprecated()`).
     */
    final public function toType(): TypeDefinition
    {
        $type = $this->type()->withDescription($this->description)->withDeprecated($this->deprecated);
        foreach ($this->examples as $example) {
            $type = $type->addExample($example);
        }

        return $type;
    }

    /**
     * This descriptor with the configured defaults resolved (a date without `format()` takes the configured format).
     * Called by the compiler, once (model.md D29); nothing to resolve by default.
     */
    public function configure(Configuration $configuration): static
    {
        return $this;
    }

    public function source(): Source
    {
        return $this->source;
    }

    public function nullable(bool $nullable = true): static
    {
        $clone = clone $this;
        $clone->nullable = $nullable;

        return $clone;
    }

    public function required(bool $required = true): static
    {
        $clone = clone $this;
        $clone->required = $required;

        return $clone;
    }

    public function sometimes(bool $sometimes = true): static
    {
        $clone = clone $this;
        $clone->sometimes = $sometimes;

        return $clone;
    }

    public function describe(string $description): static
    {
        $clone = clone $this;
        $clone->description = $description;

        return $clone;
    }

    /**
     * Adds an example.
     *
     * @param scalar|array<mixed>|null $example a JSON-ready value
     */
    public function example(mixed $example): static
    {
        $clone = clone $this;
        $clone->examples[] = $example;

        return $clone;
    }

    public function deprecated(bool $deprecated = true): static
    {
        $clone = clone $this;
        $clone->deprecated = $deprecated;

        return $clone;
    }

    /**
     * Adds validation rules (strings, rule objects, closures), opaque to the core.
     */
    public function rules(mixed ...$rules): static
    {
        $clone = clone $this;
        $clone->rules = [...$this->rules, ...array_values($rules)];

        return $clone;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isSometimes(): bool
    {
        return $this->sometimes;
    }

    /**
     * @return list<mixed>
     */
    public function declaredRules(): array
    {
        return $this->rules;
    }

    /**
     * The type without documentation: the kind and the constraints of the concrete descriptor.
     */
    abstract protected function type(): TypeDefinition;
}
