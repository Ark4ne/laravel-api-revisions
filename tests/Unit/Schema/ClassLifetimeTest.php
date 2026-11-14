<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Unrevisioned;
use Ark4ne\ApiRevisions\Schema\ClassLifetime;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\PodcastDeclaration;
use Test\TestCase;

final class ClassLifetimeTest extends TestCase
{
    public function test_a_class_without_lifetime_exists_at_every_revision(): void
    {
        self::assertEquals(Lifetime::always(), ClassLifetime::of(CustomerDeclaration::class));
        self::assertTrue(ClassLifetime::exists(CustomerDeclaration::class, ApiRevision::Genesis));
    }

    public function test_a_class_with_a_lifetime_exists_only_where_it_covers(): void
    {
        self::assertEquals(Lifetime::since(ApiRevision::Spring26), ClassLifetime::of(PodcastDeclaration::class));
        self::assertFalse(ClassLifetime::exists(PodcastDeclaration::class, ApiRevision::Winter25));
        self::assertTrue(ClassLifetime::exists(PodcastDeclaration::class, ApiRevision::Spring26));
        self::assertTrue(ClassLifetime::exists(PodcastDeclaration::class, ApiRevision::Atlas));
    }

    public function test_a_revision_of_another_enum_is_refused(): void
    {
        $this->expectException(DefinitionException::class);

        ClassLifetime::exists(PodcastDeclaration::class, Unrevisioned::Head);
    }
}
