<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\HistoryAfterUntil;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class HistoryAfterUntilTest extends TestCase
{
    use RunsChecks;

    public function test_a_was_newer_than_the_until_is_reported(): void
    {
        self::assertSame(
            [['history-after-until', 'error', 'total', 'spring-26', "The was(Spring26) of 'total' (response) is newer than its until(Winter25): it describes no revision."]],
            self::check(new HistoryAfterUntil(), static fn(): array => [
                'total' => Schema::int()->until(ApiRevision::Winter25)->was(ApiRevision::Spring26, name: 'amount'),
            ]),
        );
    }

    public function test_a_was_at_or_before_the_until_reports_nothing(): void
    {
        self::assertSame([], self::check(new HistoryAfterUntil(), static fn(): array => [
            'total' => Schema::int()->until(ApiRevision::Winter25)->was(ApiRevision::Winter25, name: 'amount'),
            'items' => Schema::list(Schema::string()->until(ApiRevision::Spring26)->was(ApiRevision::Genesis, name: 'x')),
        ]));
    }
}
