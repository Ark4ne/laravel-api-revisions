<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Test\TestCase;

final class DeclarationKindTest extends TestCase
{
    public function test_a_response_plans_down_and_a_request_up(): void
    {
        self::assertSame(Direction::Down, DeclarationKind::Response->direction());
        self::assertSame(Direction::Up, DeclarationKind::RequestBody->direction());
        self::assertSame(Direction::Up, DeclarationKind::RequestQuery->direction());
    }
}
