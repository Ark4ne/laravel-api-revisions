<?php

declare(strict_types=1);

namespace Test\Fixtures\Discovery\Nested;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;

/**
 * A declaring class found in a subdirectory of a discovery path (US 5.3).
 */
final class NestedDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return ['id' => Schema::int()];
    }
}
