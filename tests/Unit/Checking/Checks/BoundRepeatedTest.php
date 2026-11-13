<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\BoundRepeated;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class BoundRepeatedTest extends TestCase
{
    use RunsChecks;

    public function test_a_bound_called_twice_is_reported_with_the_revision_it_replaced(): void
    {
        self::assertSame(
            [
                ['bound-repeated', 'warning', 'total', 'winter-25', "since() is called more than once on 'total' (response): the last call replaces Winter25."],
                ['bound-repeated', 'warning', 'customer.name', 'atlas', "until() is called more than once on 'customer.name' (response): the last call replaces Atlas."],
                ['bound-repeated', 'warning', 'tags.*', 'genesis', "since() is called more than once on 'tags.*' (response): the last call replaces Genesis."],
            ],
            self::check(new BoundRepeated(), static fn(): array => [
                'total' => Schema::int()->since(ApiRevision::Winter25)->since(ApiRevision::Spring26),
                'customer' => Schema::object([
                    'name' => Schema::string()->until(ApiRevision::Atlas)->until(ApiRevision::Spring26),
                ]),
                'tags' => Schema::list(Schema::string()->since(ApiRevision::Genesis)->since(ApiRevision::Winter25)),
            ]),
        );
    }

    public function test_bounds_called_once_report_nothing(): void
    {
        self::assertSame([], self::check(new BoundRepeated(), static fn(): array => [
            'total' => Schema::int()->since(ApiRevision::Winter25)->until(ApiRevision::Spring26),
            ...Schema::since(ApiRevision::Winter25, ['note' => Schema::string()->since(ApiRevision::Spring26)]),
        ]));
    }
}
