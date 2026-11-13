<?php

declare(strict_types=1);

namespace Test\Fixtures\Checked;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;

/**
 * Discovered by `revision:check`: a bound called twice, a warning only (`bound-repeated`).
 */
final class WarnedDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return [
            'title' => Schema::string()->since(ApiRevision::Genesis)->since(ApiRevision::Winter25),
        ];
    }
}
