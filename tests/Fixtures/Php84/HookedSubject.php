<?php

declare(strict_types=1);

namespace Test\Fixtures\Php84;

use LogicException;

/**
 * Property hooks (PHP 8.4, loaded only there): `get_object_vars()` lists the backed one and the virtual one with a `get`
 * hook, never the write-only one, so
 * `PlainSourceReader` reads them alike.
 */
final class HookedSubject
{
    public string $backed = 'raw' {
        get => "hooked:{$this->backed}";
    }

    public string $virtual {
        get => 'virtual';
    }

    public string $writeOnly {
        set => throw new LogicException('write only');
    }

    public function __get(string $key): string
    {
        return "magic:{$key}";
    }
}
