<?php

declare(strict_types=1);

namespace Test\Unit\Compilation;

use Ark4ne\ApiRevisions\Compilation\Collision;
use Test\TestCase;

final class CollisionTest extends TestCase
{
    public function test_a_collision_keeps_its_name_and_head_keys(): void
    {
        $collision = new Collision('state', ['status', 'state']);

        self::assertSame('state', $collision->name);
        self::assertSame(['status', 'state'], $collision->headKeys);
    }
}
