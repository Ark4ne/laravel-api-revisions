<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;

/**
 * A resource nesting `PodcastDeclaration` from the revision the podcast exists in.
 */
final class ShowDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return [
            'name' => Schema::string(),
            'podcast' => Schema::resource(PodcastDeclaration::class)->since(ApiRevision::Spring26),
        ];
    }
}
