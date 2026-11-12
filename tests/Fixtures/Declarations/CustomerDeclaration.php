<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;

/**
 * The customer of an order, nested in `OrderDeclaration` (a response) and in `StoreOrderDeclaration` (a body) with its
 * own history; its response lists its orders back (a cycle, model.md D20, D49).
 */
final class CustomerDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response, DeclarationKind::RequestBody];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $probe): array
    {
        return match ($kind) {
            DeclarationKind::Response => [
                'name' => Schema::string()->was(ApiRevision::Winter25, name: 'full_name'),
                'orders' => Schema::list(Schema::resource(OrderDeclaration::class))->since(ApiRevision::Spring26),
            ],
            default => [
                'name' => Schema::string()->required()->was(ApiRevision::Winter25, name: 'full_name'),
            ],
        };
    }
}
