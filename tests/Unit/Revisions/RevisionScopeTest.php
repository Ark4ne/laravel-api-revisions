<?php

declare(strict_types=1);

namespace Test\Unit\Revisions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use RuntimeException;
use stdClass;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\IntRevision;
use Test\TestCase;

final class RevisionScopeTest extends TestCase
{
    public function test_current_is_the_head_by_default(): void
    {
        self::assertSame(ApiRevision::Atlas, (new RevisionScope())->current(ApiRevision::class));
    }

    public function test_current_is_the_base_revision_when_set(): void
    {
        $scope = new RevisionScope();

        $scope->setBase(ApiRevision::Winter25);

        self::assertSame(ApiRevision::Winter25, $scope->current(ApiRevision::class));
    }

    public function test_a_null_base_falls_back_to_the_head(): void
    {
        $scope = new RevisionScope();
        $scope->setBase(ApiRevision::Winter25);

        $scope->setBase(null);

        self::assertSame(ApiRevision::Atlas, $scope->current(ApiRevision::class));
    }

    public function test_using_sets_the_revision_inside_the_closure_only(): void
    {
        $scope = new RevisionScope();
        $scope->setBase(ApiRevision::Winter25);

        $inside = $scope->using(ApiRevision::Genesis, static fn(): mixed => $scope->current(ApiRevision::class));

        self::assertSame(ApiRevision::Genesis, $inside);
        self::assertSame(ApiRevision::Winter25, $scope->current(ApiRevision::class));
    }

    public function test_using_returns_the_result_of_the_closure(): void
    {
        $result = new stdClass();

        self::assertSame($result, (new RevisionScope())->using(ApiRevision::Genesis, static fn(): stdClass => $result));
    }

    public function test_using_restores_the_revision_when_the_closure_throws(): void
    {
        $scope = new RevisionScope();

        $thrown = null;
        try {
            $scope->using(ApiRevision::Genesis, static fn(): never => throw new RuntimeException('webhook failed'));
        } catch (RuntimeException $exception) {
            $thrown = $exception->getMessage();
        }

        self::assertSame('webhook failed', $thrown);

        self::assertSame(ApiRevision::Atlas, $scope->current(ApiRevision::class));
    }

    public function test_nested_using_restores_each_level(): void
    {
        $scope = new RevisionScope();
        $seen = [];

        $scope->using(ApiRevision::Winter25, static function () use ($scope, &$seen): void {
            $seen[] = $scope->current(ApiRevision::class);
            $scope->using(ApiRevision::Genesis, static function () use ($scope, &$seen): void {
                $seen[] = $scope->current(ApiRevision::class);
            });
            $seen[] = $scope->current(ApiRevision::class);
        });
        $seen[] = $scope->current(ApiRevision::class);

        self::assertSame([ApiRevision::Winter25, ApiRevision::Genesis, ApiRevision::Winter25, ApiRevision::Atlas], $seen);
    }

    public function test_using_wins_over_the_base_revision(): void
    {
        $scope = new RevisionScope();
        $scope->setBase(ApiRevision::Spring26);

        self::assertSame(ApiRevision::Genesis, $scope->using(ApiRevision::Genesis, static fn(): mixed => $scope->current(ApiRevision::class)));
    }

    public function test_current_of_another_enum_than_the_scope_revision_throws(): void
    {
        $scope = new RevisionScope();
        $scope->setBase(IntRevision::First);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Test\Fixtures\Revisions\IntRevision::First is not a revision of Test\Fixtures\ApiRevision.');

        $scope->current(ApiRevision::class);
    }

    public function test_the_default_provider_serves_one_process_local_scope(): void
    {
        self::assertSame(RevisionScope::get(), RevisionScope::get());
    }

    public function test_the_provider_serves_the_scope_of_the_context(): void
    {
        $scope = new RevisionScope();

        RevisionScope::provider(static fn(): RevisionScope => $scope);

        self::assertSame($scope, RevisionScope::get());
    }

    public function test_resetting_the_provider_serves_a_new_process_local_scope(): void
    {
        $before = RevisionScope::get();

        RevisionScope::provider(null);

        self::assertNotSame($before, RevisionScope::get());
    }
}
