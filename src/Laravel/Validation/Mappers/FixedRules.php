<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\RuleMapper;

/**
 * The same rules whatever the constraints: `boolean` for a bool; none for `any` and a custom type, whose descriptor
 * declares its rules (`declaredRules()`).
 */
final readonly class FixedRules implements RuleMapper
{
    /**
     * @param list<mixed> $rules
     */
    public function __construct(private array $rules = []) {}

    public function rules(TypeDefinition $type): array
    {
        return $this->rules;
    }
}
