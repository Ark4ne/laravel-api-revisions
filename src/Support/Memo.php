<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Support;

use Closure;

/**
 * Process-wide memo of values that depend only on the code (an enum's order, a compiled shape), never on a request.
 * The only static state of the package with `RevisionScope::provider()` (docs/architecture.md §16).
 *
 * @internal
 */
final class Memo
{
    /**
     * @var array<string, mixed>
     */
    private static array $values = [];

    /**
     * The value stored under `$key`, computed once by `$compute`. A computation that throws stores nothing.
     *
     * @template T
     *
     * @param Closure(): T $compute
     *
     * @return T
     */
    public static function remember(string $key, Closure $compute): mixed
    {
        if (!array_key_exists($key, self::$values)) {
            self::$values[$key] = $compute();
        }

        /** @var T */
        return self::$values[$key];
    }

    /**
     * Forgets every value (tests, Octane workers reloading code).
     */
    public static function flush(): void
    {
        self::$values = [];
    }
}
