<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\RuleMapper;

/**
 * A composite: its rule (`list` for a list, `array` for an object or a nested resource), then `min:` / `max:` (the
 * size); its items and children get their own keys (`*.`, `.`) from the `RuleCompiler`.
 */
final readonly class ArrayRules implements RuleMapper
{
    public function __construct(private string $rule) {}

    public function rules(TypeDefinition $type): array
    {
        $rules = [$this->rule];
        if ($type->min !== null) {
            $rules[] = "min:{$type->min}";
        }
        if ($type->max !== null) {
            $rules[] = "max:{$type->max}";
        }

        return $rules;
    }
}
