<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Support\Undefined;
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
     * Another `Revisioned` class, projected at the same revision with its own shape: the shape of its
     * declaration of the same kind. `DefinitionException::notRevisioned()` for any other class.
     *
     * @param class-string<Revisioned> $class
     * @param string|Closure|null $source see `string()`; its value is the subject of `$class`
     */
    public static function resource(string $class, mixed $source = null): ResourceSchema
    {
        return new ResourceSchema($class, $source);
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

    /**
     * A group added in `$revision` (included): every member exists from it, unless it declares a newer `since()`
     * itself; an older one throws `DefinitionException::sinceOlderThanGroup()`. `$default` is the default of every
     * member that declares none (§7.3). Returns the array, to spread with its string keys.
     *
     * @param array<string, Descriptor<mixed>> $fields
     *
     * @return array<string, Descriptor<mixed>>
     */
    public static function since(Revision $revision, array $fields, mixed $default = new Undefined()): array
    {
        return Groups::since($revision, $fields, $default);
    }

    /**
     * A group removed after `$revision` (included): every member exists up to it, unless it declares an older
     * `until()` itself; a newer one throws `DefinitionException::untilNewerThanGroup()`.
     *
     * @param array<string, Descriptor<mixed>> $fields
     *
     * @return array<string, Descriptor<mixed>>
     */
    public static function until(Revision $revision, array $fields): array
    {
        return Groups::until($revision, $fields);
    }

    /**
     * A custom type registered in the `TypeRegistry` (`Schema::money('total')`), built with the arguments given;
     * `DefinitionException::unknownType()` otherwise.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @return Descriptor<mixed>
     */
    public static function __callStatic(string $name, array $arguments): Descriptor
    {
        return TypeRegistry::make($name, $arguments);
    }
}
