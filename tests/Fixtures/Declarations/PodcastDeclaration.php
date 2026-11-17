<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\HasLifetime;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;

/**
 * A resource type added in `spring-26`: it does not exist for older clients.
 */
final class PodcastDeclaration implements Revisioned, HasLifetime
{
    public static function revisionedLifetime(): Lifetime
    {
        return Lifetime::since(ApiRevision::Spring26);
    }

    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return [
            'title' => Schema::string(),
            'minutes' => Schema::int()->was(ApiRevision::Spring26, name: 'duration'),
        ];
    }
}
