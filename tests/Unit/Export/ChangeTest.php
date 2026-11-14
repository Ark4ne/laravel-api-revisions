<?php

declare(strict_types=1);

namespace Test\Unit\Export;

use Ark4ne\ApiRevisions\Export\Change;
use Ark4ne\ApiRevisions\Export\ChangeKind;
use Test\TestCase;

final class ChangeTest extends TestCase
{
    public function test_each_change_describes_itself_in_one_line(): void
    {
        self::assertSame('Added `tags`.', (new Change(ChangeKind::Added, 'tags'))->describe());
        self::assertSame('Removed `name`.', (new Change(ChangeKind::Removed, 'name'))->describe());
        self::assertSame('Renamed `state` to `status`.', (new Change(ChangeKind::Renamed, 'status', 'state'))->describe());
        self::assertSame(
            'Changed the type of `total`: `{"type":"string"}` → `{"type":"integer","pattern":"^a/b$"}`.',
            (new Change(ChangeKind::TypeChanged, 'total', before: ['type' => 'string'], after: ['type' => 'integer', 'pattern' => '^a/b$']))->describe(),
        );
    }
}
