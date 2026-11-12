<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;

/**
 * A declaration breaking the contract: a plain value, or a descriptor under an integer key.
 */
final class InvalidDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response, DeclarationKind::RequestBody];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $probe): array
    {
        // @phpstan-ignore return.type (the mistake this fixture stands for)
        return $kind === DeclarationKind::Response ? ['title' => 'Draft'] : [Schema::string()];
    }
}
