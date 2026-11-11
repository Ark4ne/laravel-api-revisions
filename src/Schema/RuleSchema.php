<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;

/**
 * The escape hatch: a field typed only by its validation rules, of kind `Any` (model.md §3). Its value is rendered as
 * it is (scalars, arrays, `JsonSerializable`); its export is `any`, enriched by `describe()` and by its rules
 * implementing `DescribesSchema`.
 *
 * @extends Descriptor<mixed>
 */
final class RuleSchema extends Descriptor
{
    /**
     * The rules given to the constructor, before those added by `rules()`.
     *
     * @var list<mixed>
     */
    private readonly array $base;

    /**
     * @param array<mixed> $rules validation rules (strings, rule objects, closures), opaque to the core; keys dropped
     * @param mixed $source see `Descriptor::__construct()`
     */
    public function __construct(array $rules, mixed $source = null)
    {
        parent::__construct($source);

        $this->base = array_values($rules);
    }

    public function declaredRules(): array
    {
        return [...$this->base, ...parent::declaredRules()];
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
