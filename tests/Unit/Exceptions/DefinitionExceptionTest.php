<?php

declare(strict_types=1);

namespace Test\Unit\Exceptions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\IntRevision;
use Test\TestCase;

final class DefinitionExceptionTest extends TestCase
{
    public function test_mixed_revisions_names_both_cases_and_their_enums(): void
    {
        $exception = DefinitionException::mixedRevisions(ApiRevision::Atlas, IntRevision::First);

        self::assertSame(
            'Revisions of two enums cannot be mixed: Test\Fixtures\ApiRevision::Atlas and Test\Fixtures\Revisions\IntRevision::First.',
            $exception->getMessage(),
        );
    }

    public function test_invalid_order_names_the_enum_and_the_reason(): void
    {
        $exception = DefinitionException::invalidOrder(ApiRevision::class, 'Atlas is missing');

        self::assertSame(
            'Test\Fixtures\ApiRevision::order() must list every case exactly once: Atlas is missing.',
            $exception->getMessage(),
        );
    }

    public function test_invalid_meta_date_names_the_argument_and_the_value(): void
    {
        self::assertSame(
            "sunsetAt must be a Y-m-d date, got 'soon'.",
            DefinitionException::invalidMetaDate('sunsetAt', 'soon')->getMessage(),
        );
    }

    public function test_invalid_meta_names_the_case_and_keeps_the_reason(): void
    {
        $reason = DefinitionException::invalidMetaDate('sunsetAt', 'soon');

        $exception = DefinitionException::invalidMeta(ApiRevision::Atlas, $reason);

        self::assertSame("Invalid #[Meta] on Test\Fixtures\ApiRevision::Atlas: sunsetAt must be a Y-m-d date, got 'soon'.", $exception->getMessage());
        self::assertSame($reason, $exception->getPrevious());
    }

    public function test_foreign_revision_names_the_case_and_the_required_enum(): void
    {
        self::assertSame(
            'Test\Fixtures\Revisions\IntRevision::First is not a revision of Test\Fixtures\ApiRevision.',
            DefinitionException::foreignRevision(IntRevision::First, ApiRevision::class)->getMessage(),
        );
    }

    public function test_eager_source_names_the_type_given(): void
    {
        self::assertSame(
            'A source is a key, a dotted path or a closure, never an evaluated value: got int.',
            DefinitionException::eagerSource('int')->getMessage(),
        );
    }

    public function test_invalid_source_key_names_the_key(): void
    {
        self::assertSame(
            "Invalid source key 'a..b': a key or each segment of a dotted path must not be empty.",
            DefinitionException::invalidSourceKey('a..b')->getMessage(),
        );
    }
}
