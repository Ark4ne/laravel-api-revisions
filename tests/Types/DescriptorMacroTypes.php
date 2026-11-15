<?php

declare(strict_types=1);

namespace Test\Types;

use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Schema\StringSchema;
use Test\Fixtures\MoneySchema;

use function PHPStan\Testing\assertType;

// The descriptor macros mapped in phpstan.neon.dist (apiRevisions.macros) return the type of their receiver.
assertType(StringSchema::class, Schema::string()->whenFlag('detailed')->min(1));
assertType(MoneySchema::class, Schema::money()->documented('Total', example: 12.5));

Schema::string()->whenSomething(); // @phpstan-ignore method.notFound
