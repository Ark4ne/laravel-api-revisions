<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Support\Undefined;
use Closure;

/**
 * A field at the head: its lazy source, its type and its modifiers. Immutable: every modifier returns a new instance.
 * The extension base of custom types (docs/architecture.md §16).
 *
 * @template-covariant TValue the PHP value of the type, once normalized (covariant: a `Descriptor<string>` is a
 *                     `Descriptor<mixed>`, so `serialize()` takes a `mixed`)
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
     * @var list<Closure>
     */
    private array $conditions = [];

    /**
     * @var list<Presence>
     */
    private array $presences = [];

    private Lifetime $lifetime;

    private mixed $default;

    /**
     * @var list<array{bound: 'since'|'until', replaced: Revision}>
     */
    private array $repeatedBounds = [];

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
        $this->lifetime = Lifetime::always();
        $this->default = new Undefined();
    }

    /**
     * The PHP value of the type from what the source read (model.md §6.3), or the refusal: never throws. `null` never
     * reaches it (the pipeline handles it first); given anyway, it is refused.
     *
     * @return TValue|InvalidValue
     */
    abstract public function normalize(mixed $value): mixed;

    /**
     * The JSON-ready value of a normalized value (a `TValue`). Scalars serialize as they are; a composite receives its
     * items or children already projected and serialized (model.md D34).
     */
    public function serialize(mixed $value): mixed
    {
        return $value;
    }

    /**
     * The type with its documentation (`describe()`, `example()`, `deprecated()`) and the JSON Schema keywords of its
     * rules implementing `DescribesSchema` (merged in declaration order: a later rule wins on a shared keyword).
     */
    final public function toType(): TypeDefinition
    {
        $jsonSchema = [];
        foreach ($this->declaredRules() as $rule) {
            if ($rule instanceof DescribesSchema) {
                $keywords = $rule->toSchema();
                if (!self::isJsonReady($keywords)) {
                    throw DefinitionException::invalidJsonSchema($rule::class);
                }
                $jsonSchema = [...$jsonSchema, ...$keywords];
            }
        }
        $type = $this->type();
        $type = $type
            ->withDescription($this->description)
            ->withDeprecated($this->deprecated)
            ->withJsonSchema([...$type->jsonSchema, ...$jsonSchema]);
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

    /**
     * Keeps the field only when the closure returns `true`: called with `($value, $subject)` after the source and the
     * normalization (model.md §6.4). Several conditions must all pass. The field becomes conditional (optional in the
     * export).
     */
    public function when(Closure $condition): static
    {
        $clone = clone $this;
        $clone->conditions[] = $condition;

        return $clone;
    }

    /**
     * Keeps the field only when the subject has the key (dotted paths allowed; its own key by default, resolved by the
     * projection, model.md D37), checked before the source. The field becomes conditional (optional in the export).
     */
    public function whenHas(?string $key = null): static
    {
        $clone = clone $this;
        $clone->presences[] = Presence::has($key);

        return $clone;
    }

    /**
     * Whether the field may be omitted: it has a `when()` or a presence condition.
     */
    public function isConditional(): bool
    {
        return $this->conditions !== [] || $this->presences !== [];
    }

    /**
     * @return list<Closure> the `when()` conditions, in declaration order
     */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /**
     * @return list<Presence> the presence conditions, in declaration order
     */
    public function presences(): array
    {
        return $this->presences;
    }

    /**
     * The field exists from `$revision` included (model.md §4.1). `$default` is what an older client is deemed to send
     * (model.md §7.3); none by default. A second call replaces the first, its default included, and is recorded
     * (`repeatedBounds()`).
     */
    public function since(Revision $revision, mixed $default = new Undefined()): static
    {
        $clone = clone $this;
        $clone->lifetime = $this->lifetime->withSince($revision);
        $clone->default = $default;
        if ($this->lifetime->since !== null) {
            $clone->repeatedBounds[] = ['bound' => 'since', 'replaced' => $this->lifetime->since];
        }

        return $clone;
    }

    /**
     * The field exists up to `$revision` included, removed in `$revision->next()` (model.md §4.1). A second call
     * replaces the first and is recorded (`repeatedBounds()`).
     */
    public function until(Revision $revision): static
    {
        $clone = clone $this;
        $clone->lifetime = $this->lifetime->withUntil($revision);
        if ($this->lifetime->until !== null) {
            $clone->repeatedBounds[] = ['bound' => 'until', 'replaced' => $this->lifetime->until];
        }

        return $clone;
    }

    public function lifetime(): Lifetime
    {
        return $this->lifetime;
    }

    /**
     * The default given to `since()`, an `Undefined` without one.
     */
    public function default(): mixed
    {
        return $this->default;
    }

    /**
     * The bounds set more than once, one entry per extra call, in call order, with the revision it replaced (reported
     * by the check, model.md §4.4 rule 5).
     *
     * @return list<array{bound: 'since'|'until', replaced: Revision}>
     */
    public function repeatedBounds(): array
    {
        return $this->repeatedBounds;
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
     * Scalars, nulls and arrays of them, at any depth: what a `TypeDefinition` may hold (serializable, closure-free).
     */
    private static function isJsonReady(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isJsonReady($item)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /**
     * The type without documentation: the kind and the constraints of the concrete descriptor.
     */
    abstract protected function type(): TypeDefinition;
}
