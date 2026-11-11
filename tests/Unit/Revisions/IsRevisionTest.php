<?php

declare(strict_types=1);

namespace Test\Unit\Revisions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\MetaReader;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\IntRevision;
use Test\TestCase;

final class IsRevisionTest extends TestCase
{
    public function test_is_before_and_is_after_are_strict(): void
    {
        self::assertTrue(ApiRevision::Winter25->isBefore(ApiRevision::Spring26));
        self::assertFalse(ApiRevision::Spring26->isBefore(ApiRevision::Spring26));
        self::assertTrue(ApiRevision::Atlas->isAfter(ApiRevision::Genesis));
        self::assertFalse(ApiRevision::Spring26->isAfter(ApiRevision::Spring26));
    }

    public function test_is_at_least_and_is_at_most_include_the_revision_itself(): void
    {
        self::assertTrue(ApiRevision::Spring26->isAtLeast(ApiRevision::Spring26));
        self::assertTrue(ApiRevision::Atlas->isAtLeast(ApiRevision::Spring26));
        self::assertFalse(ApiRevision::Winter25->isAtLeast(ApiRevision::Spring26));
        self::assertTrue(ApiRevision::Spring26->isAtMost(ApiRevision::Spring26));
        self::assertTrue(ApiRevision::Genesis->isAtMost(ApiRevision::Spring26));
        self::assertFalse(ApiRevision::Atlas->isAtMost(ApiRevision::Spring26));
    }

    public function test_comparisons_follow_the_order_not_the_declaration(): void
    {
        // Atlas is declared first but is the head.
        self::assertTrue(ApiRevision::Genesis->isBefore(ApiRevision::Atlas));
    }

    public function test_previous_and_next_walk_the_timeline(): void
    {
        self::assertSame(ApiRevision::Genesis, ApiRevision::Winter25->previous());
        self::assertSame(ApiRevision::Spring26, ApiRevision::Winter25->next());
        self::assertNull(ApiRevision::Genesis->previous());
        self::assertNull(ApiRevision::Atlas->next());
    }

    public function test_latest_and_oldest_are_the_bounds_of_the_order(): void
    {
        self::assertSame(ApiRevision::Atlas, ApiRevision::latest());
        self::assertSame(ApiRevision::Genesis, ApiRevision::oldest());
        self::assertSame(IntRevision::Third, IntRevision::latest());
    }

    public function test_comparing_with_a_revision_of_another_enum_throws(): void
    {
        $this->expectException(DefinitionException::class);

        ApiRevision::Atlas->isBefore(IntRevision::First);
    }

    public function test_meta_delegates_to_the_reader(): void
    {
        self::assertSame('First published revision', ApiRevision::Genesis->meta()->description);
        self::assertSame(MetaReader::of(ApiRevision::Genesis), ApiRevision::Genesis->meta());
    }
}
