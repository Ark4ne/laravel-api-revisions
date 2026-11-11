<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;

/**
 * A value the type refuses (model.md §6.3), returned by `Descriptor::normalize()` instead of thrown: the value error
 * handler decides (strict: `ValueException`; lenient: `$cast`, the PHP cast of the value, and a report).
 */
final readonly class InvalidValue
{
    public function __construct(
        public mixed $value,
        public Kind $expected,
        public mixed $cast,
    ) {}
}
