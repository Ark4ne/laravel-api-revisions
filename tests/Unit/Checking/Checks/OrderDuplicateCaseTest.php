<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Checking\Checks\OrderDuplicateCase;
use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\DuplicateCaseRevision;
use Test\TestCase;

final class OrderDuplicateCaseTest extends TestCase
{
    public function test_a_case_listed_twice_in_the_order_is_reported(): void
    {
        self::assertSame(
            [['order-duplicate-case', 'error', DuplicateCaseRevision::class, null, 'first', 'First is listed 2 times in order(): list every case once.']],
            self::diagnostics(DuplicateCaseRevision::class, new OrderDuplicateCase()),
        );
    }

    public function test_an_order_listing_each_case_once_reports_nothing(): void
    {
        self::assertSame([], self::diagnostics(ApiRevision::class, new OrderDuplicateCase()));
    }

    /**
     * The diagnostics of `$check` run on `$enum` without class.
     *
     * @param class-string<Revision> $enum
     *
     * @return list<array{string, string, string, ?string, ?string, string}>
     */
    public static function diagnostics(string $enum, Check $check): array
    {
        $finder = new readonly class implements ClassFinder {
            public function find(): array
            {
                return [];
            }
        };
        $found = [];
        foreach ((new Checker($enum, $finder, [$check]))->run() as $diagnostic) {
            $found[] = [$diagnostic->code, $diagnostic->severity->value, $diagnostic->class, $diagnostic->field, $diagnostic->revision, $diagnostic->message];
        }

        return $found;
    }
}
