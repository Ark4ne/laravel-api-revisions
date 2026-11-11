<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Support;

use Closure;

/**
 * The single invoker of every closure the library calls (source, `when()`, `down:`, `up:`), with one signature:
 * `($value, $subject)` (model.md §3, D18). A closure may declare fewer parameters: PHP ignores the extra arguments.
 * A first-class callable of an internal function (`strlen(...)`) is strict about its arguments: wrap it in a closure.
 *
 * @internal
 */
final class Invoke
{
    /**
     * @param Closure $closure declaring any prefix of `($value, $subject)`, typed as the application needs
     *                         (`fn (int $cents, Order $order)`): not `Closure(mixed, mixed)`, which would refuse them
     */
    public static function value(Closure $closure, mixed $value, mixed $subject): mixed
    {
        return $closure($value, $subject);
    }
}
