<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;

/**
 * A plain value declared instead of a descriptor (`'x' => $this->x` in a resource): wrapped by
 * the bridge of a declaring class so that it compiles and projects like a field. A nullable `any` whose source gives
 * the value captured, rendered as it is in every revision; reported by the check (`eager-value`). Never declared by
 * hand: declare a descriptor.
 *
 * @extends Descriptor<mixed>
 */
final class EagerSchema extends Descriptor
{
    private function __construct(private readonly mixed $value)
    {
        parent::__construct(static fn(): mixed => $value);
    }

    /**
     * The field of the value `$value`, nullable.
     */
    public static function of(mixed $value): self
    {
        return (new self($value))->nullable();
    }

    /**
     * The value captured.
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * Always: a plain value may come from a native condition met on the subject of the compilation and missing on
     * another, or be left out of `toArray()` by a PHP `if`; the render then omits it.
     */
    public function isConditional(): bool
    {
        return true;
    }

    /**
     * Any value as it is; `null` refused, as by every descriptor (the pipeline handles it first).
     */
    public function normalize(mixed $value): mixed
    {
        return $value ?? new InvalidValue(null, Kind::Any, null);
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::of(Kind::Any);
    }
}
