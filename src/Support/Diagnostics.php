<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * The diagnostics collected by a check run (Notification pattern, docs/architecture.md §12): added in the order they
 * are found, never thrown.
 *
 * @implements IteratorAggregate<int, Diagnostic>
 */
final class Diagnostics implements Countable, IteratorAggregate
{
    /**
     * @var list<Diagnostic>
     */
    private array $diagnostics = [];

    public function add(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /**
     * @return list<Diagnostic>
     */
    public function all(): array
    {
        return $this->diagnostics;
    }

    /**
     * @return list<Diagnostic>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->diagnostics, static fn(Diagnostic $diagnostic): bool => $diagnostic->isError()));
    }

    /**
     * @return list<Diagnostic>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->diagnostics, static fn(Diagnostic $diagnostic): bool => $diagnostic->severity === Severity::Warning));
    }

    /**
     * Whether one diagnostic at least is an error: the check fails.
     */
    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    public function count(): int
    {
        return count($this->diagnostics);
    }

    /**
     * @return ArrayIterator<int, Diagnostic>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->diagnostics);
    }
}
