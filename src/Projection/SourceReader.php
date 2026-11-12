<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Projection;

/**
 * How the projector reads a subject (model.md §6.2, Strategy): `PlainSourceReader` in the core, decorated by the
 * Laravel layer for Eloquent models (`EloquentSourceReader`: `peek()` and the presences without lazy load). A key the
 * subject does not have reads `null`; the errors of the subject itself are never hidden.
 */
interface SourceReader
{
    /**
     * The value at `$path` on `$subject`, `null` when a segment is missing or `null`.
     *
     * @param non-empty-list<string> $path
     */
    public function read(mixed $subject, array $path): mixed;

    /**
     * Whether the subject has every segment of `$path` (a `whenHas()`), a key holding `null` included.
     *
     * @param non-empty-list<string> $path
     */
    public function has(mixed $subject, array $path): bool;

    /**
     * Whether every relation of `$path` is loaded on the subject, without loading it (a `whenLoaded()`).
     *
     * @param non-empty-list<string> $path
     */
    public function loaded(mixed $subject, array $path): bool;

    /**
     * The value of `$key` when the subject already holds it without side effect, `null` otherwise: the `$value` given
     * to a source closure.
     */
    public function peek(mixed $subject, string $key): mixed;
}
