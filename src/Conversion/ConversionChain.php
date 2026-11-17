<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion;

use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;

/**
 * The conversions of one field at one revision in one direction (§5): the steps between consecutive
 * shapes, in the order they apply (down: from the head to the oldest shape; up: from the client's shape to the head).
 * Planned once at compilation from types and references only, so it serializes; `bind()` attaches the converters at
 * run time. Immutable.
 */
final readonly class ConversionChain
{
    /**
     * @param list<ConversionStep> $steps in the order they apply
     */
    private function __construct(
        public Direction $direction,
        public array $steps,
    ) {}

    /**
     * A chain without step: the value is kept as it is.
     */
    public static function empty(Direction $direction): self
    {
        return new self($direction, []);
    }

    /**
     * Appends the step from `$from` to `$to`, the types of two consecutive shapes in the direction of the chain. With
     * an explicit converter (`$explicit`: the `was()` at `$change` declares `down:` / `up:` for this direction) the
     * step is explicit, whatever the types. Otherwise two types holding the same values add no step (same kind, enum
     * class, custom name and fragment, or any fragment of a name `Converters::identity()` declares, items and
     * children: constraints, formats and documentation never convert a value); else the registry's converter, or a
     * missing step when it has none.
     *
     * @param int $change the index of the `was()` in its history, newest first
     */
    public function withStep(TypeDefinition $from, TypeDefinition $to, int $change, bool $explicit, Converters $converters): self
    {
        $key = Converters::keyOf($from, $to, $this->direction);
        if ($explicit) {
            $step = new ConversionStep(StepKind::Explicit, $key, $from, $to, $change);
        } elseif (self::holdSameValues($from, $to, $converters)) {
            return $this;
        } else {
            $kind = $converters->find($from, $to, $this->direction) === null ? StepKind::Missing : StepKind::Registered;
            $step = new ConversionStep($kind, $key, $from, $to, $change);
        }

        return new self($this->direction, [...$this->steps, $step]);
    }

    /**
     * This chain with its converters attached: a registered step from `$converters` by its types, an explicit one from
     * `$explicit` by the index of its `was()`. A step left without converter throws when applied.
     *
     * @param array<int, ExplicitConverter|null> $explicit the `down:` (down chain) or `up:` (up chain) of each `was()`
     *                                                     of the field, by index in its history, newest first
     */
    public function bind(Converters $converters, array $explicit): self
    {
        $steps = [];
        foreach ($this->steps as $step) {
            $steps[] = $step->withConverter(match ($step->kind) {
                StepKind::Registered => $converters->find($step->from, $step->to, $this->direction),
                StepKind::Explicit => $explicit[$step->change] ?? null,
                StepKind::Missing => null,
            });
        }

        return new self($this->direction, $steps);
    }

    /**
     * Runs every step on the value, `$subject` passed to the closures: the down side, whose closures
     * all receive the projected subject. `null` is returned as it is (the pipeline handles it). Throws
     * `ConversionException::missing()` on a step without converter (missing, or not bound). The upgrader runs an up
     * chain step by step instead (`stepOf()`): it normalizes the value to each shape and passes each step its own
     * snapshot.
     *
     * @param mixed $value a normalized value of the first step's source type
     */
    public function apply(mixed $value, mixed $subject = null): mixed
    {
        if ($value === null) {
            return null;
        }
        foreach ($this->steps as $step) {
            $value = $step->apply($value, $subject);
        }

        return $value;
    }

    /**
     * The step crossing the `was()` at index `$change`, `null` when the types hold the same values there.
     */
    public function stepOf(int $change): ?ConversionStep
    {
        foreach ($this->steps as $step) {
            if ($step->change === $change) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The steps without converter in the registry or the history (reported by `conversion-missing`).
     *
     * @return list<ConversionStep>
     */
    public function missing(): array
    {
        return array_values(array_filter($this->steps, static fn(ConversionStep $step): bool => $step->kind === StepKind::Missing));
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    /**
     * Whether a value of `$a` is a value of `$b` as it is: the same kind, enum class, custom name and fragment (a custom
     * type declares its shape there), and recursively
     * the same items and the same children (by key, in any order). Constraints, formats and documentation are left
     * out: they validate or serialize a value, never convert it (a date stays a `DateTimeImmutable`).
     */
    private static function holdSameValues(TypeDefinition $a, TypeDefinition $b, Converters $converters): bool
    {
        $fragments = $a->fragment === $b->fragment || ($a->name !== null && $converters->isIdentity($a->name));
        if ($a->kind !== $b->kind || $a->enum !== $b->enum || $a->name !== $b->name || !$fragments
            || $a->resource !== $b->resource) {
            return false;
        }
        if ($a->items !== null || $b->items !== null) {
            return $a->items !== null && $b->items !== null && self::holdSameValues($a->items, $b->items, $converters);
        }
        if (count($a->children) !== count($b->children)) {
            return false;
        }
        foreach ($a->children as $key => $child) {
            if (!isset($b->children[$key]) || !self::holdSameValues($child, $b->children[$key], $converters)) {
                return false;
            }
        }

        return true;
    }
}
