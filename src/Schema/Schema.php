<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

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
}
