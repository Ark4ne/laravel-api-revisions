<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use BackedEnum;
use Closure;

/**
 * The vocabulary: one static factory per type, each returning its typed, immutable, lazy descriptor. The source is
 * the last argument: `null` (the declared key), a key or dotted path, or a closure `($value, $subject)`; never an
 * evaluated value (`DefinitionException::eagerSource()`).
 */
final class Schema
{
    /**
     * @param string|Closure|null $source natively `mixed`: in a file without `strict_types`, PHP would turn an
     *                                    evaluated value (`$order->total`) into a key instead of refusing it
     */
    public static function string(mixed $source = null): StringSchema
    {
        return new StringSchema($source);
    }

    /**
     * @param string|Closure|null $source natively `mixed`: in a file without `strict_types`, PHP would turn an
     *                                    evaluated value (`$order->total`) into a key instead of refusing it
     */
    public static function int(mixed $source = null): IntSchema
    {
        return new IntSchema($source);
    }

    /**
     * @param string|Closure|null $source natively `mixed`: in a file without `strict_types`, PHP would turn an
     *                                    evaluated value (`$order->total`) into a key instead of refusing it
     */
    public static function float(mixed $source = null): FloatSchema
    {
        return new FloatSchema($source);
    }

    /**
     * @param string|Closure|null $source natively `mixed`: in a file without `strict_types`, PHP would turn an
     *                                    evaluated value (`$order->total`) into a key instead of refusing it
     */
    public static function bool(mixed $source = null): BoolSchema
    {
        return new BoolSchema($source);
    }

    /**
     * @param string|Closure|null $source see `string()`
     */
    public static function date(mixed $source = null): DateSchema
    {
        return new DateSchema($source);
    }

    /**
     * @param string|Closure|null $source see `string()`
     */
    public static function dateTime(mixed $source = null): DateTimeSchema
    {
        return new DateTimeSchema($source);
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param class-string<TEnum> $enum
     * @param string|Closure|null $source see `string()`
     *
     * @return EnumSchema<TEnum>
     */
    public static function enum(string $enum, mixed $source = null): EnumSchema
    {
        return new EnumSchema($enum, $source);
    }

    /**
     * @param Descriptor<mixed> $items each element of the list is its subject
     * @param string|Closure|null $source see `string()`
     */
    public static function list(Descriptor $items, mixed $source = null): ListSchema
    {
        return new ListSchema($items, $source);
    }

    /**
     * @param array<string, Descriptor<mixed>> $children by key
     * @param string|Closure|null $source see `string()`; without one, the children read the parent's subject
     */
    public static function object(array $children, mixed $source = null): ObjectSchema
    {
        return new ObjectSchema($children, $source);
    }

    /**
     * The escape hatch: a field typed only by its validation rules (`Kind::Any`).
     *
     * @param array<mixed> $rules strings, rule objects, closures; a rule implementing `DescribesSchema` enriches the export
     * @param string|Closure|null $source see `string()`
     */
    public static function rule(array $rules, mixed $source = null): RuleSchema
    {
        return new RuleSchema($rules, $source);
    }
}
