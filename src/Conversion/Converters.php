<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\BackedToEnum;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\BoolToInt;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\BoolToString;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\DateTimeToDate;
use Ark4ne\ApiRevisions\Conversion\BuiltIn\DateToDateTime;
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
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Closure;

/**
 * The registry of automatic conversions, keyed by `(from, to, Direction)` (model.md §5), a side being a `Kind` or, for a
 * custom type, its name (every custom type is `Kind::Custom`). A factory builds
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
     * @var array<string, true> the custom types whose values do not depend on their fragment, by name
     */
    private array $identities = [];

    /**
     * The built-in conversions of model.md §5 (D25, D40, D41, D90), in both directions.
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
            [Kind::Date, Kind::DateTime, $always(new DateToDateTime())],
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

        // lossless down (the day a response falls back to), lossy up (the time a client sent dropped), D90
        return $registry
            ->register(Kind::DateTime, Kind::Date, Direction::Down, $always(new DateTimeToDate()))
            ->register(Kind::DateTime, Kind::Date, Direction::Up, $always(new DateTimeToDate(lossless: false)));
    }

    /**
     * Registers (or replaces) the factory of a pair in one direction. A side is a `Kind`, or the name of a custom type
     * (`'money'`, the name its `TypeDefinition::custom()` declares). `Kind::Custom` or an empty name throws
     * `DefinitionException::invalidConversionSide()`: no type has them.
     *
     * @param Closure(TypeDefinition $from, TypeDefinition $to): ?Converter $factory
     */
    public function register(Kind|string $from, Kind|string $to, Direction $direction, Closure $factory): self
    {
        foreach ([$from, $to] as $side) {
            if ($side === Kind::Custom || $side === '') {
                throw DefinitionException::invalidConversionSide($side === '' ? '' : $side->value);
            }
        }
        $clone = clone $this;
        $clone->factories[self::key($from, $to, $direction)] = $factory;

        return $clone;
    }

    /**
     * Declares that the values of the custom type `$name` do not depend on its fragment (model.md D82): two types of
     * that name differing only by their fragment plan no conversion step, in both directions. An empty name throws
     * `DefinitionException::invalidConversionSide()`.
     */
    public function identity(string $name): self
    {
        if ($name === '') {
            throw DefinitionException::invalidConversionSide('');
        }
        $clone = clone $this;
        $clone->identities[$name] = true;

        return $clone;
    }

    /**
     * Whether `identity($name)` was declared.
     */
    public function isIdentity(string $name): bool
    {
        return isset($this->identities[$name]);
    }

    /**
     * The converter from `$from` to `$to` in `$direction`, `null` when none is automatic.
     */
    public function find(TypeDefinition $from, TypeDefinition $to, Direction $direction): ?Converter
    {
        $factory = $this->factories[self::keyOf($from, $to, $direction)] ?? null;

        return $factory === null ? null : $factory($from, $to);
    }

    /**
     * The serializable key of a pair: `int>float:down`; a custom type by its name, `custom(money)>float:down`.
     */
    public static function key(Kind|string $from, Kind|string $to, Direction $direction): string
    {
        return self::side($from) . '>' . self::side($to) . ':' . $direction->value;
    }

    private static function side(Kind|string $side): string
    {
        return $side instanceof Kind ? $side->value : "custom({$side})";
    }

    /**
     * The key of the pair of two types: a custom type (`Kind::Custom`) is keyed by its name.
     */
    public static function keyOf(TypeDefinition $from, TypeDefinition $to, Direction $direction): string
    {
        return self::key(self::sideOf($from), self::sideOf($to), $direction);
    }

    private static function sideOf(TypeDefinition $type): Kind|string
    {
        return $type->name ?? $type->kind;
    }

    private static function toEnum(TypeDefinition $from, TypeDefinition $to): ?Converter
    {
        return $to->enum === null || $to->backing === null ? null : new BackedToEnum($to->enum, $to->backing);
    }
}
