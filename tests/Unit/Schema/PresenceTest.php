<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\Presence;
use Test\TestCase;

final class PresenceTest extends TestCase
{
    public function test_a_presence_without_key_checks_the_field_own_key(): void
    {
        self::assertNull(Presence::has()->path);
    }

    public function test_a_presence_checks_a_key_or_a_dotted_path(): void
    {
        self::assertSame(['total'], Presence::has('total')->path);
        self::assertSame(['customer', 'email'], Presence::has('customer.email')->path);
    }

    public function test_a_presence_refuses_an_empty_segment(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Invalid presence key 'customer.': a key or each segment of a dotted path must not be empty.");

        Presence::has('customer.');
    }

    public function test_a_presence_is_serializable(): void
    {
        self::assertEquals(Presence::has('a.b'), unserialize(serialize(Presence::has('a.b'))));
    }
}
