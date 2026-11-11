<?php

declare(strict_types=1);

namespace Test\Types;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\Enums\OrderStatus;

use function PHPStan\Testing\assertType;

/*
 * Analysed by PHPStan, never run.
 */

assertType('Ark4ne\ApiRevisions\Schema\DateSchema', Schema::date()->format('d/m/Y')->nullable());
assertType('Ark4ne\ApiRevisions\Schema\DateTimeSchema', Schema::dateTime()->configure(new Configuration()));
assertType('Ark4ne\ApiRevisions\Schema\EnumSchema<Test\Fixtures\Enums\OrderStatus>', Schema::enum(OrderStatus::class)->required());
assertType('Ark4ne\ApiRevisions\Schema\InvalidValue|Test\Fixtures\Enums\OrderStatus', Schema::enum(OrderStatus::class)->normalize('paid'));
assertType('class-string<Test\Fixtures\Enums\OrderStatus>', Schema::enum(OrderStatus::class)->enum());
assertType('Ark4ne\ApiRevisions\Schema\InvalidValue|DateTimeImmutable', Schema::date()->normalize('2026-04-01'));
