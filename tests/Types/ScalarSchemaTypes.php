<?php

declare(strict_types=1);

namespace Test\Types;

use Ark4ne\ApiRevisions\Schema\Schema;
use stdClass;

use function PHPStan\Testing\assertType;

/*
 * Analysed by PHPStan, never run. Each helper returns its concrete descriptor, kept through every modifier.
 */

assertType('Ark4ne\ApiRevisions\Schema\StringSchema', Schema::string()->nullable()->min(1)->describe('x'));
assertType('Ark4ne\ApiRevisions\Schema\IntSchema', Schema::int('total')->max(10)->required());
assertType('Ark4ne\ApiRevisions\Schema\FloatSchema', Schema::float()->min(0.5)->rules('numeric'));
assertType('Ark4ne\ApiRevisions\Schema\BoolSchema', Schema::bool()->deprecated());
assertType('Ark4ne\ApiRevisions\Schema\InvalidValue|int', Schema::int()->normalize('12'));

// Source closures declare any prefix of ($value, $subject), typed as the application needs.
assertType('Ark4ne\ApiRevisions\Schema\StringSchema', Schema::string(static fn(mixed $value, stdClass $order): string => 'x'));
assertType('Ark4ne\ApiRevisions\Schema\IntSchema', Schema::int(static fn(int $cents): int => $cents));

// A bool has no bounds: PHPStan must keep refusing it (the ignore fails if the error disappears).
Schema::bool()->min(1); // @phpstan-ignore method.notFound
