<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use BackedEnum;
use ReflectionEnum;
use ReflectionNamedType;

/**
 * A case of a backed enum: accepts the case or its backed value (for an int-backed enum, what `IntSchema` accepts:
 * `'3'`, `3.0`), serialized as its backed value. Its type exports the values (§6.3). A refused value
 * keeps its backed value as cast (`'2'` → `2` for an int-backed enum; lenient mode renders it).
 *
 * @template TEnum of BackedEnum
 *
 * @extends Descriptor<TEnum>
 */
final class EnumSchema extends Descriptor
{
    /**
     * @var class-string<TEnum>
     */
    private readonly string $enum;

    private readonly Kind $backing;

    /**
     * Reads the backed value of an int-backed enum; `null` for a string-backed one.
     */
    private readonly ?IntSchema $ints;

    /**
     * @param class-string<TEnum> $enum a backed enum (`DefinitionException::notBackedEnum()` otherwise)
     * @param mixed $source see `Descriptor::__construct()`
     */
    public function __construct(string $enum, mixed $source = null)
    {
        parent::__construct($source);

        if (!enum_exists($enum) || !is_subclass_of($enum, BackedEnum::class)) {
            throw DefinitionException::notBackedEnum($enum);
        }

        $type = (new ReflectionEnum($enum))->getBackingType();
        $this->enum = $enum;
        $this->backing = $type instanceof ReflectionNamedType && $type->getName() === 'int' ? Kind::Int : Kind::String;
        $this->ints = $this->backing === Kind::Int ? new IntSchema() : null;
    }

    /**
     * @return class-string<TEnum>
     */
    public function enum(): string
    {
        return $this->enum;
    }

    public function normalize(mixed $value): mixed
    {
        if ($value instanceof $this->enum) {
            return $value;
        }

        $backed = $this->ints?->normalize($value) ?? $value;
        if ($backed instanceof InvalidValue) {
            $backed = $value;
        }
        $case = match (true) {
            $this->backing === Kind::Int && is_int($backed), $this->backing === Kind::String && is_string($backed) => $this->enum::tryFrom($backed),
            default => null,
        };

        return $case ?? new InvalidValue($value, Kind::Enum, is_int($backed) || is_string($backed) ? $backed : null);
    }

    /**
     * A case as its backed value; anything else (never a normalized value) as it is.
     */
    public function serialize(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::of(Kind::Enum)->withEnum(
            $this->enum,
            $this->backing,
            array_map(static fn(BackedEnum $case): int|string => $case->value, $this->enum::cases()),
        );
    }
}
