<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;

/**
 * A list: accepts any `iterable` (arrays, generators, collections), normalized to a `list` of its elements (keys
 * dropped). Each element is then the subject of the items descriptor (model.md §6.1): the projection runs every item
 * through its own pipeline, and the list's `when()`, `down:` and `serialize()` receive the projected items (model.md
 * D34).
 *
 * @extends Descriptor<list<mixed>>
 */
final class ListSchema extends Descriptor
{
    /**
     * @var Descriptor<mixed>
     */
    private Descriptor $items;

    private ?int $min = null;

    private ?int $max = null;

    /**
     * @param Descriptor<mixed> $items each element is its subject; its own source, if any, is read on the element
     * @param mixed $source see `Descriptor::__construct()`
     */
    public function __construct(Descriptor $items, mixed $source = null)
    {
        parent::__construct($source);

        $this->items = $items;
    }

    /**
     * @return Descriptor<mixed>
     */
    public function items(): Descriptor
    {
        return $this->items;
    }

    protected function parts(): array
    {
        return [$this->items];
    }

    /**
     * Minimum size.
     */
    public function min(int $size): self
    {
        $clone = clone $this;
        $clone->min = $size;

        return $clone;
    }

    /**
     * Maximum size.
     */
    public function max(int $size): self
    {
        $clone = clone $this;
        $clone->max = $size;

        return $clone;
    }

    public function configure(Configuration $configuration): static
    {
        $clone = clone $this;
        $clone->items = $this->items->configure($configuration);

        return $clone;
    }

    public function normalize(mixed $value): mixed
    {
        return is_iterable($value)
            ? iterator_to_array($value, false)
            : new InvalidValue($value, Kind::List, null);
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::of(Kind::List)
            ->withMin($this->min)
            ->withMax($this->max)
            ->withItems($this->items->toType());
    }
}
