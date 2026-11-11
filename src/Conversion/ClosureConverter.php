<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion;

use Ark4ne\ApiRevisions\Support\Invoke;
use Closure;

/**
 * A user closure `($value, $subject)` given as `down:` or `up:` (model.md §5), called through `Support\Invoke`.
 * Explicit; its loss is unknown, so it declares itself lossy, and it adds no rule.
 */
final readonly class ClosureConverter implements ExplicitConverter
{
    /**
     * @param Closure $closure declaring any prefix of `($value, $subject)`
     */
    public function __construct(public Closure $closure) {}

    public function convert(mixed $value, mixed $subject = null): mixed
    {
        return Invoke::value($this->closure, $value, $subject);
    }

    public function lossless(): bool
    {
        return false;
    }

    public function extraRules(): array
    {
        return [];
    }
}
