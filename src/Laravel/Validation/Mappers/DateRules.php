<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\RuleMapper;

/**
 * A date or a date-time: `date_format:` of its format, the wire format the export documents (D62); `date` for a type without format.
 */
final readonly class DateRules implements RuleMapper
{
    public function rules(TypeDefinition $type): array
    {
        return [$type->format === null ? 'date' : "date_format:{$type->format}"];
    }
}
