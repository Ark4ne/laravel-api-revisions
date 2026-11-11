<?php

declare(strict_types=1);

namespace Test\Types;

use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\MoneySchema;

use function PHPStan\Testing\assertType;

// The custom types mapped in phpstan.neon.dist (apiRevisions.types) are typed; any other name is reported.
assertType(MoneySchema::class, Schema::money('total'));
assertType(MoneySchema::class, Schema::money(currency: 'EUR')->since(ApiRevision::Atlas));

Schema::percent(); // @phpstan-ignore staticMethod.notFound
