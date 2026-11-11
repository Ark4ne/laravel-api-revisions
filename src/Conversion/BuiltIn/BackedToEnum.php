<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\Converter;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use BackedEnum;

/**
 * string or int → backed enum: the value read as the backing type when lossless, then `::from()`; an unknown
 * value throws `ConversionException::invalidEnumValue()` (model.md D41).
 */
final class BackedToEnum implements Converter
{
    /**
     * @param class-string<BackedEnum> $enum
     * @param Kind $backing `Int` or `String`
     */
    public function __construct(
        private readonly string $enum,
        private readonly Kind $backing,
    ) {}

    /**
     * Unlike the other converters, it validates: any value that is not a lossless backed value of a case throws
     * `ConversionException::invalidEnumValue()`.
     */
    public function convert(mixed $value, mixed $subject = null): mixed
    {
        $backed = match (true) {
            $this->backing === Kind::Int && is_int($value), $this->backing === Kind::String && is_string($value) => $value,
            $this->backing === Kind::Int && is_string($value) && (string) (int) $value === $value => (int) $value,
            $this->backing === Kind::String && is_int($value) => (string) $value,
            default => null,
        };

        return ($backed === null ? null : $this->enum::tryFrom($backed))
            ?? throw ConversionException::invalidEnumValue($this->enum, $value);
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
