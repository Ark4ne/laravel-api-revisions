<?php

// No strict_types on purpose: Laravel application files do not declare it, and PHP then coerces scalar arguments.

namespace Test\Fixtures;

use Ark4ne\ApiRevisions\Schema\Schema;
use Closure;

/**
 * Declarations written in a file without `strict_types`: an evaluated value must still be refused as a source.
 */
final class NonStrictDeclarations
{
    /**
     * @return list<Closure(): object>
     */
    public static function evaluatedSources(): array
    {
        return [
            static fn(): object => Schema::string(1250), // @phpstan-ignore argument.type
            static fn(): object => Schema::int(1250), // @phpstan-ignore argument.type
            static fn(): object => Schema::float(12.5), // @phpstan-ignore argument.type
            static fn(): object => Schema::bool(true), // @phpstan-ignore argument.type
        ];
    }
}
