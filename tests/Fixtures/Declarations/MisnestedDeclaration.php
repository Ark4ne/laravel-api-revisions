<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;

/**
 * A body nesting, deep in an object and a list, a class that declares no body.
 */
final class MisnestedDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::RequestBody];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return [
            'title' => Schema::string(),
            'basket' => Schema::object([
                'orders' => Schema::list(Schema::resource(OrderDeclaration::class)),
            ]),
        ];
    }
}
