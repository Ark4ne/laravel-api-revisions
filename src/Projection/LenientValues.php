<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Projection;

use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Closure;

/**
 * Value errors render their fallback and go to a reporter: production never breaks on drifting data (model.md D19).
 */
final readonly class LenientValues implements ValueErrorHandler
{
    /**
     * @param Closure(ValueException): void|null $reporter called with every error (the Laravel layer: `report()`, once
     *                                                  per class and field); none ignores them
     */
    public function __construct(private ?Closure $reporter = null) {}

    public function handle(ValueException $error, mixed $fallback): mixed
    {
        if ($this->reporter !== null) {
            ($this->reporter)($error);
        }

        return $fallback;
    }
}
