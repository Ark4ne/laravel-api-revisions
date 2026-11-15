<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\ClosureConverter;
use Ark4ne\ApiRevisions\Conversion\ExplicitConverter;
use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Support\Json;
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

    private History $history;

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
        $this->history = History::none();
        $this->default = new Undefined();
    }

    /**
     * A macro of `DescriptorMacros` (`->whenInFields('title')`), run on this descriptor with the arguments given
     * (model.md D87); `DefinitionException::unknownMacro()` otherwise.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $name, array $arguments): static
    {
        return DescriptorMacros::call($this, $name, $arguments);
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
                if (!Json::isReady($keywords)) {
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
     * This descriptor with its own configured defaults resolved (a date without `format()` takes the configured format,
     * model.md D29); nothing to resolve by default. A composite or custom type holding descriptors resolves them with
     * their `configured()`. Called through `configured()`, and alone by the projector before serializing (once per
     * field per render): keep it cheap, and never let `serialize()` rely on the history being configured.
     */
    public function configure(Configuration $configuration): static
    {
        return $this;
    }

    /**
     * A copy with the configured defaults resolved: its own (`configure()`) and those of the schemas of its `was()`.
     * Called by the compiler, once per declaration (model.md D29).
     */
    final public function configured(Configuration $configuration): static
    {
        $clone = clone $this->configure($configuration);
        $clone->history = $clone->history->configured($configuration);

        return $clone;
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
     * Keeps the field only when the relation `$relation` (dotted for a nested one) is loaded on the subject, checked
     * before the source: the source never triggers a lazy load (model.md §6.4, D61). On a subject without relations,
     * `whenHas($relation)`. The field becomes conditional (optional in the export).
     */
    public function whenLoaded(string $relation): static
    {
        $clone = clone $this;
        $clone->presences[] = Presence::loaded($relation);

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
        $clone->history = $this->history->withLifetime($this->lifetime()->withSince($revision));
        $clone->default = $default;
        if ($this->lifetime()->since !== null) {
            $clone->repeatedBounds[] = ['bound' => 'since', 'replaced' => $this->lifetime()->since];
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
        $clone->history = $this->history->withLifetime($this->lifetime()->withUntil($revision));
        if ($this->lifetime()->until !== null) {
            $clone->repeatedBounds[] = ['bound' => 'until', 'replaced' => $this->lifetime()->until];
        }

        return $clone;
    }

    /**
     * This descriptor with the lifetime and default a group resolved for it, recording nothing (`Groups`).
     *
     * @internal
     */
    public function inGroup(Lifetime $lifetime, mixed $default): static
    {
        $clone = clone $this;
        $clone->history = $this->history->withLifetime($lifetime);
        $clone->default = $default;

        return $clone;
    }

    public function lifetime(): Lifetime
    {
        return $this->history->lifetime;
    }

    /**
     * Up to `$revision` included, the field had another `name`, another type (`schema`, its source never read), or
     * both, than in the next newer shape (model.md §4.2); `down` / `up` convert between the two when the automatic
     * conversion is not the one wanted: a closure `($value, $subject)` (kept as a `ClosureConverter`) or a `Cast`.
     * Ordered by revision whatever the call order. Throws `DefinitionException::invalidHistory()` on the head, on a
     * revision already changed or for an empty name, `DefinitionException::sourceInHistory()` for a `schema` with a
     * source, a lifetime, a history or a default.
     *
     * @param non-empty-string|null $name
     * @param Descriptor<mixed>|null $schema
     */
    public function was(
        Revision $revision,
        ?string $name = null,
        ?Descriptor $schema = null,
        Closure|ExplicitConverter|null $down = null,
        Closure|ExplicitConverter|null $up = null,
    ): static {
        // @phpstan-ignore identical.alwaysFalse (checked at run time: declarations are application code)
        if ($name === '') {
            throw DefinitionException::emptyHistoryName($revision);
        }
        if ($name === null && $schema === null && $down === null && $up === null) {
            throw DefinitionException::emptyChange($revision);
        }
        if ($schema !== null && !$schema->describesOnlyAType()) {
            throw DefinitionException::sourceInHistory($revision);
        }
        $clone = clone $this;
        $change = new Change($revision, $name, $schema, self::explicit($down), self::explicit($up));
        $clone->history = $this->history->withChange($change);

        return $clone;
    }

    /**
     * A closure given to `was()` as a `ClosureConverter`; a cast as it is.
     */
    private static function explicit(Closure|ExplicitConverter|null $conversion): ?ExplicitConverter
    {
        return $conversion instanceof Closure ? new ClosureConverter($conversion) : $conversion;
    }

    /**
     * No source of its own (the declared key, or the parent's subject for an object), no lifetime, no history, no
     * default, no condition, and the same for its items and children: what a `was(schema:)` may be.
     */
    private function describesOnlyAType(): bool
    {
        if (!($this->source->isDeclaredKey() || $this->source->isParent())
            || !$this->history->isEmpty()
            || !Undefined::is($this->default)
            || $this->isConditional()) {
            return false;
        }
        foreach ($this->parts() as $part) {
            if (!$part->describesOnlyAType()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The descriptors a composite is made of (its items, its children); none for a scalar.
     *
     * @return list<Descriptor<mixed>>
     */
    protected function parts(): array
    {
        return [];
    }

    public function history(): History
    {
        return $this->history;
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
     * The type without documentation: the kind and the constraints of the concrete descriptor.
     */
    abstract protected function type(): TypeDefinition;
}
