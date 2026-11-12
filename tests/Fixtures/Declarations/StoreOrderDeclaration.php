<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Enums\OrderStatus;

/**
 * A request of the reference scenario without Laravel, declaring a body and a query (model.md §7, D48), built without
 * subject as a `FormRequest` is.
 */
final class StoreOrderDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::RequestBody, DeclarationKind::RequestQuery];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $probe): array
    {
        return match ($kind) {
            DeclarationKind::RequestQuery => [
                'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
            ],
            default => [
                'title' => Schema::string()->required(),
                'total' => Schema::int()->was(ApiRevision::Winter25, schema: Schema::string()),
                'surname' => Schema::string()->required()->since(ApiRevision::Atlas),
                'customer' => Schema::resource(CustomerDeclaration::class),
            ],
        };
    }
}
