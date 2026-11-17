<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Projection;

use Ark4ne\ApiRevisions\Exceptions\ValueException;

/**
 * What the projection does with a value it cannot render: throw it (`StrictValues`), or
 * render a fallback and report it (`LenientValues`). Chosen from `Configuration::$strictValues`.
 */
interface ValueErrorHandler
{
    /**
     * The value to render instead (`$fallback`: the PHP cast of a refused value, `null` otherwise), or throws.
     */
    public function handle(ValueException $error, mixed $fallback): mixed;
}
