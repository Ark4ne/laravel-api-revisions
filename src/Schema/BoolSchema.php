<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;

/**
 * A boolean: accepts booleans, `0` / `1` and `'0'` / `'1'`.
 *
 * @extends Descriptor<bool>
 */
final class BoolSchema extends Descriptor
{
    public function normalize(mixed $value): mixed
    {
        return match ($value) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => new InvalidValue($value, Kind::Bool, is_scalar($value) ? (bool) $value : null),
        };
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::of(Kind::Bool);
    }
}
