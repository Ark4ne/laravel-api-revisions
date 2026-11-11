<?php

declare(strict_types=1);

namespace Test\Types;

use Ark4ne\ApiRevisions\Schema\Schema;

use function PHPStan\Testing\assertType;

/*
 * Analysed by PHPStan, never run.
 */

assertType('Ark4ne\ApiRevisions\Schema\ListSchema', Schema::list(Schema::string())->min(1)->nullable());
assertType('Ark4ne\ApiRevisions\Schema\ObjectSchema', Schema::object(['street' => Schema::string()])->required());
assertType('Ark4ne\ApiRevisions\Schema\InvalidValue|list<mixed>', Schema::list(Schema::int())->normalize([1]));
assertType('array<string, Ark4ne\ApiRevisions\Schema\Descriptor<mixed>>', Schema::object([])->children());

// An object has no size: PHPStan must keep refusing it.
Schema::object([])->min(1); // @phpstan-ignore method.notFound
