<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Revisions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Closure;

/**
 * The only holder of the current revision (Ambient Context): a base revision (set by the middleware) under a stack of
 * `using()` revisions. The core reaches the scope of the running context through `RevisionScope::get()`, served by
 * the provider set once at boot (`provider()`); the default provider serves one process-local scope. The Laravel
 * layer provides a `scoped` container binding, reset on every request (Octane).
 */
final class RevisionScope
{
    /**
     * Configuration, not state: set once at boot (docs/architecture.md §16).
     *
     * @var (Closure(): self)|null
     */
    private static ?Closure $provider = null;

    private ?Revision $base = null;

    /**
     * @var list<Revision>
     */
    private array $stack = [];

    /**
     * Sets how the scope of the running context is found; `null` restores the default, a new process-local scope.
     *
     * @param (Closure(): self)|null $provider
     */
    public static function provider(?Closure $provider): void
    {
        self::$provider = $provider;
    }

    /**
     * The scope of the running context.
     */
    public static function get(): self
    {
        if (self::$provider === null) {
            $local = new self();
            self::$provider = static fn(): self => $local;
        }

        return (self::$provider)();
    }

    /**
     * The revision of the context when no `using()` runs (the resolved revision of the request).
     */
    public function setBase(?Revision $revision): void
    {
        $this->base = $revision;
    }

    /**
     * The innermost `using()` revision, else the base, else the head of `$class`. A revision of another enum throws
     * `DefinitionException::foreignRevision()`.
     *
     * @template T of Revision
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function current(string $class): Revision
    {
        $current = $this->stack === []
            ? $this->base ?? RevisionOrder::latest($class)
            : $this->stack[array_key_last($this->stack)];

        if (!$current instanceof $class) {
            throw DefinitionException::foreignRevision($current, $class);
        }

        return $current;
    }

    /**
     * Runs `$callback` with `$revision` as the current revision, then restores the previous one, even when it throws.
     * The revision does not outlive the callback: render inside it, not in a resource or generator returned by it.
     *
     * @template TResult
     *
     * @param Closure(): TResult $callback
     *
     * @return TResult
     */
    public function using(Revision $revision, Closure $callback): mixed
    {
        $this->stack[] = $revision;

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }
}
