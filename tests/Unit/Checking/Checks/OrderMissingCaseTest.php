<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\OrderMissingCase;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\DuplicateCaseRevision;
use Test\Fixtures\Revisions\MissingCaseRevision;
use Test\TestCase;

final class OrderMissingCaseTest extends TestCase
{
    public function test_a_case_missing_from_the_order_is_reported(): void
    {
        self::assertSame(
            [['order-missing-case', 'error', MissingCaseRevision::class, null, 'second', 'Second is missing from order(): list every case once, oldest first.']],
            self::diagnose(MissingCaseRevision::class),
        );
    }

    public function test_a_complete_order_reports_nothing(): void
    {
        self::assertSame([], self::diagnose(ApiRevision::class));
    }

    public function test_a_case_listed_twice_is_not_missing(): void
    {
        self::assertSame(
            [['order-missing-case', 'error', DuplicateCaseRevision::class, null, 'second', 'Second is missing from order(): list every case once, oldest first.']],
            self::diagnose(DuplicateCaseRevision::class),
        );
    }

    /**
     * @param class-string<Revision> $enum
     *
     * @return list<array{string, string, string, ?string, ?string, string}>
     */
    private static function diagnose(string $enum): array
    {
        return OrderDuplicateCaseTest::diagnostics($enum, new OrderMissingCase());
    }
}
