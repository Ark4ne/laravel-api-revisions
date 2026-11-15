<?php

declare(strict_types=1);

namespace Test\Unit\PHPStan\Data;

use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\Enums\OrderStatus;

use function PHPStan\Testing\assertType;

assertType('Ark4ne\ApiRevisions\Schema\StringSchema', Schema::string()->whenFlag());
assertType('Ark4ne\ApiRevisions\Schema\StringSchema', Schema::string()->whenFlag('detailed')->nullable()->min(2));
assertType('Ark4ne\ApiRevisions\Schema\IntSchema', Schema::int()->documented('Count', example: 3));
assertType('Test\Fixtures\MoneySchema', Schema::money()->documented('Total'));
assertType('Ark4ne\ApiRevisions\Schema\ListSchema', Schema::list(Schema::string())->whenFlag());
assertType('Ark4ne\ApiRevisions\Schema\EnumSchema<Test\Fixtures\Enums\OrderStatus>', Schema::enum(OrderStatus::class)->whenFlag()->required());
