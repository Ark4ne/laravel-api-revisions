<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\NameCollision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class NameCollisionTest extends TestCase
{
    use RunsChecks;

    public function test_two_fields_under_one_name_are_reported_at_each_revision(): void
    {
        self::assertSame(
            [
                ['name-collision', 'error', 'status', 'genesis', "'status' is written by 'state', 'status' at Genesis (response)."],
                ['name-collision', 'error', 'customer.name', 'genesis', "'customer.name' is written by 'label', 'name' at Genesis (response)."],
                ['name-collision', 'error', 'status', 'winter-25', "'status' is written by 'state', 'status' at Winter25 (response)."],
            ],
            self::check(new NameCollision(), static fn(): array => [
                'state' => Schema::string()->was(ApiRevision::Winter25, name: 'status'),
                'status' => Schema::string(),
                'customer' => Schema::object([
                    'label' => Schema::string()->was(ApiRevision::Genesis, name: 'name'),
                    'name' => Schema::string(),
                ]),
            ]),
        );
    }

    public function test_a_name_in_both_the_body_and_the_query_is_reported(): void
    {
        self::assertSame(
            [['name-collision', 'error', 'page', 'atlas', "'page' is declared in both the body and the query at Atlas: Laravel validates them merged."]],
            self::check(
                new NameCollision(),
                static fn(DeclarationKind $kind): array => $kind === DeclarationKind::RequestBody
                    ? ['page' => Schema::int()->since(ApiRevision::Atlas)]
                    : ['page' => Schema::int()],
                [DeclarationKind::RequestBody, DeclarationKind::RequestQuery],
            ),
        );
    }

    public function test_distinct_names_report_nothing(): void
    {
        self::assertSame([], self::check(new NameCollision(), static fn(): array => [
            'status' => Schema::string()->was(ApiRevision::Winter25, name: 'state'),
            'label' => Schema::string(),
        ]));
    }
}
