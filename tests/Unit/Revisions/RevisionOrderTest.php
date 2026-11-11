<?php

declare(strict_types=1);

namespace Test\Unit\Revisions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\DuplicateCaseRevision;
use Test\Fixtures\Revisions\EmptyRevision;
use Test\Fixtures\Revisions\ForeignCaseRevision;
use Test\Fixtures\Revisions\IntRevision;
use Test\Fixtures\Revisions\MissingCaseRevision;
use Test\Fixtures\Revisions\NotListRevision;
use Test\TestCase;

final class RevisionOrderTest extends TestCase
{
    public function test_revisions_are_ordered_by_their_order_method_not_by_declaration(): void
    {
        self::assertSame(0, RevisionOrder::index(ApiRevision::Genesis));
        self::assertSame(1, RevisionOrder::index(ApiRevision::Winter25));
        self::assertSame(2, RevisionOrder::index(ApiRevision::Spring26));
        self::assertSame(3, RevisionOrder::index(ApiRevision::Atlas));
    }

    public function test_int_backed_revisions_are_ordered_by_their_order_method_not_by_value(): void
    {
        self::assertSame(0, RevisionOrder::index(IntRevision::First));
        self::assertSame(1, RevisionOrder::index(IntRevision::Second));
        self::assertSame(2, RevisionOrder::index(IntRevision::Third));
        self::assertSame([IntRevision::Second, IntRevision::Third], RevisionOrder::between(IntRevision::Second, IntRevision::Third));
    }

    /**
     * @return iterable<string, array{ApiRevision, ApiRevision, int}>
     */
    public static function comparisons(): iterable
    {
        yield 'older' => [ApiRevision::Winter25, ApiRevision::Spring26, -1];
        yield 'equal' => [ApiRevision::Spring26, ApiRevision::Spring26, 0];
        yield 'newer' => [ApiRevision::Atlas, ApiRevision::Genesis, 1];
    }

    #[DataProvider('comparisons')]
    public function test_compare_follows_the_order(ApiRevision $a, ApiRevision $b, int $expected): void
    {
        self::assertSame($expected, RevisionOrder::compare($a, $b) <=> 0);
    }

    public function test_the_head_is_the_last_revision_of_the_order(): void
    {
        self::assertSame(ApiRevision::Atlas, RevisionOrder::latest(ApiRevision::class));
    }

    public function test_the_oldest_is_the_first_revision_of_the_order(): void
    {
        self::assertSame(ApiRevision::Genesis, RevisionOrder::oldest(ApiRevision::class));
    }

    public function test_previous_and_next_walk_the_order(): void
    {
        self::assertSame(ApiRevision::Winter25, RevisionOrder::previous(ApiRevision::Spring26));
        self::assertSame(ApiRevision::Atlas, RevisionOrder::next(ApiRevision::Spring26));
    }

    public function test_previous_of_the_oldest_revision_is_null(): void
    {
        self::assertNull(RevisionOrder::previous(ApiRevision::Genesis));
    }

    public function test_next_of_the_head_is_null(): void
    {
        self::assertNull(RevisionOrder::next(ApiRevision::Atlas));
    }

    public function test_between_lists_both_bounds_included_oldest_first(): void
    {
        self::assertSame(
            [ApiRevision::Winter25, ApiRevision::Spring26, ApiRevision::Atlas],
            RevisionOrder::between(ApiRevision::Winter25, ApiRevision::Atlas),
        );
        self::assertSame([ApiRevision::Spring26], RevisionOrder::between(ApiRevision::Spring26, ApiRevision::Spring26));
    }

    public function test_between_is_empty_when_from_is_newer_than_to(): void
    {
        self::assertSame([], RevisionOrder::between(ApiRevision::Atlas, ApiRevision::Genesis));
    }

    public function test_comparing_revisions_of_two_enums_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Cannot compare revisions of two enums: Test\Fixtures\ApiRevision::Atlas and Test\Fixtures\Revisions\IntRevision::First.');

        RevisionOrder::compare(ApiRevision::Atlas, IntRevision::First);
    }

    public function test_between_revisions_of_two_enums_throws(): void
    {
        $this->expectException(DefinitionException::class);

        RevisionOrder::between(ApiRevision::Genesis, IntRevision::Third);
    }

    public function test_an_order_missing_a_case_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Test\Fixtures\Revisions\MissingCaseRevision::order() must list every case exactly once: Second is missing.');

        RevisionOrder::index(MissingCaseRevision::First);
    }

    public function test_an_order_listing_a_case_twice_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Test\Fixtures\Revisions\DuplicateCaseRevision::order() must list every case exactly once: First is listed twice.');

        RevisionOrder::latest(DuplicateCaseRevision::class);
    }

    public function test_an_order_listing_a_case_of_another_enum_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Test\Fixtures\Revisions\ForeignCaseRevision::order() must list every case exactly once: Test\Fixtures\Revisions\IntRevision is not a case of the enum.');

        RevisionOrder::index(ForeignCaseRevision::First);
    }

    public function test_an_empty_order_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Test\Fixtures\Revisions\EmptyRevision::order() must list every case exactly once: the order is empty.');

        RevisionOrder::latest(EmptyRevision::class);
    }

    public function test_an_order_that_is_not_a_list_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Test\Fixtures\Revisions\NotListRevision::order() must list every case exactly once: the order is not a list.');

        RevisionOrder::latest(NotListRevision::class);
    }

    public function test_an_invalid_order_throws_on_every_use(): void
    {
        foreach ([1, 2] as $attempt) {
            try {
                RevisionOrder::oldest(MissingCaseRevision::class);
                self::fail("Attempt {$attempt} did not throw.");
            } catch (DefinitionException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
