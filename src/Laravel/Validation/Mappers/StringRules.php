<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Validation\Mappers;

use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Laravel\Validation\RuleMapper;

/**
 * `string`, `min:` / `max:` (length), `regex:` from the pattern, and the rule of a known JSON Schema format:
 * `email`, `uri` → `url`, `uuid`, `ipv4`, `ipv6`, `date` → `date_format:Y-m-d`, `date-time` → `date`; another format
 * gives none (export only).
 */
final readonly class StringRules implements RuleMapper
{
    /**
     * The rule of each JSON Schema format Laravel validates.
     */
    private const array FORMATS = [
        'email' => 'email',
        'uri' => 'url',
        'uuid' => 'uuid',
        'ipv4' => 'ipv4',
        'ipv6' => 'ipv6',
        'date' => 'date_format:Y-m-d',
        'date-time' => 'date',
    ];

    public function rules(TypeDefinition $type): array
    {
        $rules = ['string'];
        if ($type->min !== null) {
            $rules[] = "min:{$type->min}";
        }
        if ($type->max !== null) {
            $rules[] = "max:{$type->max}";
        }
        if ($type->pattern !== null) {
            $rules[] = "regex:{$type->pattern}";
        }
        $format = self::FORMATS[$type->format ?? ''] ?? null;
        if ($format !== null) {
            $rules[] = $format;
        }

        return $rules;
    }
}
