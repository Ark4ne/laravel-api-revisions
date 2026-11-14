<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\Address;
use Test\Fixtures\MoneySchema;

/**
 * A response sharing the component `Address` with the resource it nests (`WarehouseDeclaration`), and using the
 * custom type `money` twice (model.md D70).
 */
final class ShippingDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return [
            'from' => Schema::resource(WarehouseDeclaration::class, 'from'),
            'to' => Address::schema('to'),
            'price' => new MoneySchema('price'),
            'fee' => (new MoneySchema('fee'))->nullable(),
        ];
    }
}
