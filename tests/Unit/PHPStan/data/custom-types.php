<?php

declare(strict_types=1);

namespace Test\Unit\PHPStan\Data;

use Ark4ne\ApiRevisions\Schema\Schema;

use function PHPStan\Testing\assertType;

assertType('Test\Fixtures\MoneySchema', Schema::money());
assertType('Test\Fixtures\MoneySchema', Schema::money('total', currency: 'EUR'));
assertType('Test\Fixtures\MoneySchema', Schema::money()->nullable()->describe('Total'));
assertType('Ark4ne\ApiRevisions\Schema\StringSchema', Schema::string());
