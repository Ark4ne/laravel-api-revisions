<?php

declare(strict_types=1);

namespace Test\Unit\Revisions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Meta;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\Unrevisioned;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class UnrevisionedTest extends TestCase
{
    public function test_its_one_case_is_the_head_and_the_oldest(): void
    {
        self::assertSame([Unrevisioned::Head], Unrevisioned::order());
        self::assertSame(Unrevisioned::Head, self::enum()::latest());
        self::assertSame(Unrevisioned::Head, self::enum()::oldest());
        self::assertNull(Unrevisioned::Head->previous());
        self::assertNull(Unrevisioned::Head->next());
    }

    public function test_it_compares_equal_to_itself(): void
    {
        self::assertFalse(Unrevisioned::Head->isBefore(Unrevisioned::Head));
        self::assertFalse(Unrevisioned::Head->isAfter(Unrevisioned::Head));
        self::assertTrue(Unrevisioned::Head->isAtLeast(Unrevisioned::Head));
        self::assertTrue(Unrevisioned::Head->isAtMost(Unrevisioned::Head));
    }

    public function test_it_has_no_metadata(): void
    {
        self::assertEquals(new Meta(), Unrevisioned::Head->meta());
    }

    public function test_it_is_current_and_used_like_any_revision(): void
    {
        self::assertSame(Unrevisioned::Head, self::enum()::current());
        self::assertSame(Unrevisioned::Head, Unrevisioned::using(Unrevisioned::Head, static fn(): Revision => self::enum()::current()));
    }

    public function test_a_revision_of_another_enum_is_refused(): void
    {
        $this->expectException(DefinitionException::class);

        Unrevisioned::using(ApiRevision::Genesis, static fn(): null => null);
    }

    public function test_a_revision_of_another_enum_is_refused_in_a_comparison(): void
    {
        $this->expectException(DefinitionException::class);

        Unrevisioned::Head->isBefore(ApiRevision::Genesis);
    }

    /**
     * The enum seen through the contract, as the library sees a configured enum.
     *
     * @return class-string<Revision>
     */
    private static function enum(): string
    {
        return Unrevisioned::class;
    }
}
