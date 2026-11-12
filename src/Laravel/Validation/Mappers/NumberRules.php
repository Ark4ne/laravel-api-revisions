<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\RuleMapper;

/**
 * A number: its rule (`integer` for an int, `numeric` for a float), then `min:` / `max:` (the value).
 */
final readonly class NumberRules implements RuleMapper
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
