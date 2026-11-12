<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\RuleMapper;
use Illuminate\Validation\Rules\Enum;

/**
 * A backed enum: Laravel's `Enum` rule on its class (its backed values); none for a type without class.
 */
final readonly class EnumRules implements RuleMapper
{
    public function rules(TypeDefinition $type): array
    {
        return $type->enum === null ? [] : [new Enum($type->enum)];
    }
}
