<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Projection;

use Ark4ne\ApiRevisions\Exceptions\ValueException;

/**
 * Value errors throw: development, tests.
 */
final readonly class StrictValues implements ValueErrorHandler
{
    public function handle(ValueException $error, mixed $fallback): mixed
    {
        throw $error;
    }
}
