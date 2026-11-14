<?php

declare(strict_types=1);

namespace Test\Fixtures\Checks;

/**
 * The names a consumer reserves (JSON:API: `type`, `id`), injected into its check by the container.
 */
final readonly class ReservedNames
{
    /**
     * @param list<string> $names
     */
    public function __construct(public array $names = ['type', 'id']) {}
}
