<?php

declare(strict_types=1);

namespace Test\Unit\Definition;

use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\IntRevision;
use Test\TestCase;

final class LifetimeTest extends TestCase
{
    public function test_always_covers_every_revision(): void
    {
        $always = Lifetime::always();

        self::assertNull($always->since);
        self::assertNull($always->until);
        foreach (ApiRevision::order() as $revision) {
            self::assertTrue($always->covers($revision));
        }
    }

    public function test_since_covers_from_its_revision_included(): void
    {
        $lifetime = Lifetime::always()->withSince(ApiRevision::Winter25);

        self::assertSame(ApiRevision::Winter25, $lifetime->since);
        self::assertFalse($lifetime->covers(ApiRevision::Genesis));
        self::assertTrue($lifetime->covers(ApiRevision::Winter25));
        self::assertTrue($lifetime->covers(ApiRevision::Atlas));
    }

    public function test_until_covers_up_to_its_revision_included(): void
    {
        $lifetime = Lifetime::always()->withUntil(ApiRevision::Winter25);

        self::assertSame(ApiRevision::Winter25, $lifetime->until);
        self::assertTrue($lifetime->covers(ApiRevision::Genesis));
        self::assertTrue($lifetime->covers(ApiRevision::Winter25));
        self::assertFalse($lifetime->covers(ApiRevision::Spring26));
    }

    public function test_since_and_until_write_one_bound(): void
    {
        self::assertEquals(Lifetime::always()->withSince(ApiRevision::Winter25), Lifetime::since(ApiRevision::Winter25));
        self::assertEquals(Lifetime::always()->withUntil(ApiRevision::Winter25), Lifetime::until(ApiRevision::Winter25));
        self::assertEquals(Lifetime::always()->withSince(ApiRevision::Genesis)->withUntil(ApiRevision::Spring26), Lifetime::since(ApiRevision::Genesis)->withUntil(ApiRevision::Spring26));
    }

    public function test_a_lifetime_of_one_revision_covers_only_it(): void
    {
        $lifetime = Lifetime::always()->withSince(ApiRevision::Winter25)->withUntil(ApiRevision::Winter25);

        self::assertSame(
            [false, true, false, false],
            array_map($lifetime->covers(...), ApiRevision::order()),
        );
    }

    public function test_a_bound_can_be_removed(): void
    {
        $lifetime = Lifetime::always()->withSince(ApiRevision::Atlas)->withUntil(ApiRevision::Atlas);

        self::assertEquals(Lifetime::always(), $lifetime->withSince(null)->withUntil(null));
    }

    public function test_since_newer_than_until_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(sprintf(
            'A field cannot exist from %1$s::Spring26 until %1$s::Winter25: its since is newer than its until.',
            ApiRevision::class,
        ));

        Lifetime::always()->withUntil(ApiRevision::Winter25)->withSince(ApiRevision::Spring26);
    }

    public function test_until_older_than_since_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('its since is newer than its until');

        Lifetime::always()->withSince(ApiRevision::Spring26)->withUntil(ApiRevision::Genesis);
    }

    public function test_bounds_of_two_enums_cannot_be_mixed(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Revisions of two enums cannot be mixed');

        Lifetime::always()->withSince(ApiRevision::Genesis)->withUntil(IntRevision::Second);
    }

    public function test_a_revision_of_another_enum_cannot_be_covered(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Revisions of two enums cannot be mixed');

        Lifetime::always()->withUntil(ApiRevision::Atlas)->covers(IntRevision::First);
    }

    public function test_a_lifetime_is_serializable(): void
    {
        $lifetime = Lifetime::always()->withSince(ApiRevision::Genesis)->withUntil(ApiRevision::Spring26);

        self::assertEquals($lifetime, unserialize(serialize($lifetime)));
    }
}
