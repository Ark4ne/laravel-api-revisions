<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\Address;

/**
 * A response using the component `Address`, nullable and as the items of a list; nested in `ShippingDeclaration`.
 */
final class WarehouseDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return [
            'address' => Address::schema('address')->nullable(),
            'docks' => Schema::list(Address::schema(), 'docks'),
        ];
    }
}
