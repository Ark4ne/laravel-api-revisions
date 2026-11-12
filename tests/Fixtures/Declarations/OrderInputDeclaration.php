<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Upgrade\UpgradesInput;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Enums\OrderStatus;

/**
 * The order request body of the reference scenario (model.md §7) without Laravel: the fields of `StoreOrderRequest`
 * that change over the revisions, and its hook splitting `name` at `Atlas`.
 */
final class OrderInputDeclaration implements Revisioned, UpgradesInput
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::RequestBody];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return [
            'title' => Schema::string()->required(),
            'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
            'total' => Schema::int()->min(0)
                ->was(
                    ApiRevision::Winter25,
                    schema: Schema::string(),
                    up: static fn(string $amount): int => (int) round(((float) $amount) * 100),
                )
                ->was(ApiRevision::Genesis, schema: Schema::float()),
            ...Schema::since(ApiRevision::Atlas, [
                'first_name' => Schema::string()->required(),
                'last_name' => Schema::string()->required(),
                'priority' => Schema::int()->required()->since(ApiRevision::Atlas, default: 0),
            ], default: null),
            ...Schema::until(ApiRevision::Spring26, [
                'name' => Schema::string()->required(),
            ]),
        ];
    }

    public function upgrade(Revision $to, array $input): array
    {
        if ($to === ApiRevision::Atlas && is_string($input['name'] ?? null)) {
            [$input['first_name'], $input['last_name']] = explode(' ', $input['name'], 2) + [1 => ''];
            unset($input['name']);
        }

        return $input;
    }
}
