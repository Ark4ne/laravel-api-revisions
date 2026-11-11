<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Conversion\ClosureConverter;
use Ark4ne\ApiRevisions\Schema\Change;
use Ark4ne\ApiRevisions\Schema\IntSchema;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class ChangeTest extends TestCase
{
    public function test_a_change_keeps_its_arguments_and_leaves_the_others_to_the_newer_shape(): void
    {
        $schema = new IntSchema();
        $down = new ClosureConverter(static fn(int $cents): float => $cents / 100);

        $change = new Change(ApiRevision::Winter25, schema: $schema, down: $down);

        self::assertSame(ApiRevision::Winter25, $change->revision);
        self::assertNull($change->name);
        self::assertSame($schema, $change->schema);
        self::assertSame($down, $change->down);
        self::assertNull($change->up);
    }
}
