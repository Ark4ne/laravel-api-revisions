<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\BackedToEnum;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\BoolToInt;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\BoolToString;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\EnumToBacked;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\FloatToInt;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\FloatToString;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\IntToBool;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\IntToFloat;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\IntToString;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\NumericStringToFloat;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\NumericStringToInt;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\StringToBool;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Closure;

/**
 * The registry of automatic conversions, keyed by `(from Kind, to Kind, Direction)` (model.md §5). A factory builds
 * the converter for the two types (an enum target needs its class), or returns `null` when the pair does not convert
 * automatically for those types. Immutable: `register()` returns a new registry.
 */
final class Converters
{
    /**
     * @var array<string, Closure(TypeDefinition, TypeDefinition): ?Converter>
     */
    private array $factories = [];

    /**
     * The built-in conversions of model.md §5 (D25, D40, D41), in both directions.
     */
    public static function builtIn(): self
    {
        $registry = new self();
        $always = static fn(Converter $converter): Closure => static fn(): Converter => $converter;
        $pairs = [
            [Kind::Int, Kind::Float, $always(new IntToFloat())],
            [Kind::Int, Kind::String, $always(new IntToString())],
            [Kind::Float, Kind::String, $always(new FloatToString())],
            [Kind::Bool, Kind::String, $always(new BoolToString())],
            [Kind::Bool, Kind::Int, $always(new BoolToInt())],
            [Kind::String, Kind::Int, $always(new NumericStringToInt())],
            [Kind::String, Kind::Float, $always(new NumericStringToFloat())],
            [Kind::Float, Kind::Int, $always(new FloatToInt())],
            [Kind::Int, Kind::Bool, $always(new IntToBool())],
            [Kind::String, Kind::Bool, $always(new StringToBool())],
            [Kind::Enum, Kind::String, $always(new EnumToBacked(Kind::String))],
            [Kind::Enum, Kind::Int, static fn(TypeDefinition $from): ?Converter => $from->backing === Kind::Int ? new EnumToBacked(Kind::Int) : null],
            [Kind::String, Kind::Enum, self::toEnum(...)],
            [Kind::Int, Kind::Enum, self::toEnum(...)],
        ];
        foreach (Direction::cases() as $direction) {
            foreach ($pairs as [$from, $to, $factory]) {
                $registry = $registry->register($from, $to, $direction, $factory);
            }
        }

        return $registry;
    }

    /**
     * @param Closure(TypeDefinition $from, TypeDefinition $to): ?Converter $factory
     */
    public function register(Kind $from, Kind $to, Direction $direction, Closure $factory): self
    {
        $clone = clone $this;
        $clone->factories[self::key($from, $to, $direction)] = $factory;

        return $clone;
    }

    /**
     * The converter from `$from` to `$to` in `$direction`, `null` when none is automatic.
     */
    public function find(TypeDefinition $from, TypeDefinition $to, Direction $direction): ?Converter
    {
        $factory = $this->factories[self::key($from->kind, $to->kind, $direction)] ?? null;

        return $factory === null ? null : $factory($from, $to);
    }

    /**
     * The serializable key of a pair: `int>float:down`.
     */
    public static function key(Kind $from, Kind $to, Direction $direction): string
    {
        return "{$from->value}>{$to->value}:{$direction->value}";
    }

    private static function toEnum(TypeDefinition $from, TypeDefinition $to): ?Converter
    {
        return $to->enum === null || $to->backing === null ? null : new BackedToEnum($to->enum, $to->backing);
    }
}
