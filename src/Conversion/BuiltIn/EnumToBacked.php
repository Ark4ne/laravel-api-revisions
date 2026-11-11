<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;
use Ark4ne\ApiRevisions\Definition\Kind;
use BackedEnum;

/**
 * backed enum → string or int, through its backed value (model.md D41).
 */
final class EnumToBacked implements Converter
{
    /**
     * @param Kind $target `String` (any enum) or `Int` (an int-backed enum)
     */
    public function __construct(
        private readonly Kind $target,
    ) {}

    /**
     * A value of another type (never a normalized one) is returned as it is.
     */
    public function convert(mixed $value, mixed $subject = null): mixed
    {
        if (!$value instanceof BackedEnum) {
            return $value;
        }

        return $this->target === Kind::String ? (string) $value->value : $value->value;
    }

    public function lossless(): bool
    {
        return true;
    }

    public function extraRules(): array
    {
        return [];
    }
}
