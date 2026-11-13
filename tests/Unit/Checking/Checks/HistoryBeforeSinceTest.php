<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\HistoryBeforeSince;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class HistoryBeforeSinceTest extends TestCase
{
    use RunsChecks;

    public function test_a_was_older_than_the_since_is_reported(): void
    {
        self::assertSame(
            [
                ['history-before-since', 'error', 'total', 'genesis', "The was(Genesis) of 'total' (response) is older than its since(Winter25): it describes no revision."],
                ['history-before-since', 'error', 'customer.name', 'genesis', "The was(Genesis) of 'customer.name' (response) is older than its since(Spring26): it describes no revision."],
            ],
            self::check(new HistoryBeforeSince(), static fn(): array => [
                'total' => Schema::int()->since(ApiRevision::Winter25)->was(ApiRevision::Genesis, name: 'amount')->was(ApiRevision::Winter25, name: 'sum'),
                'customer' => Schema::object([
                    'name' => Schema::string()->since(ApiRevision::Spring26)->was(ApiRevision::Genesis, name: 'label'),
                ]),
            ]),
        );
    }

    public function test_a_was_at_or_after_the_since_reports_nothing(): void
    {
        self::assertSame([], self::check(new HistoryBeforeSince(), static fn(): array => [
            'total' => Schema::int()->since(ApiRevision::Winter25)->was(ApiRevision::Winter25, name: 'amount'),
            'name' => Schema::string()->was(ApiRevision::Genesis, name: 'label'),
        ]));
    }
}
