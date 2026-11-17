<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;

/**
 * Requests declaring what retiring `Genesis` makes dead, among declarations it leaves alive. The query
 * declares a `name` first, without bound, so the locator must prefer the body's `name` naming `Genesis`; `meta.code`
 * comes before the top-level `code`, so the locator must not read a nested key for a top-level one.
 */
final readonly class PrunableDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::RequestQuery, DeclarationKind::RequestBody];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return match ($kind) {
            DeclarationKind::RequestQuery => [
                'name' => Schema::string(),
                'page' => Schema::int()->since(ApiRevision::Genesis),
            ],
            default => [
                'title' => Schema::string(),
                'legacy' => Schema::string()->until(ApiRevision::Genesis),
                'name' => Schema::string()->was(ApiRevision::Genesis, name: 'label'),
                'meta' => Schema::object([
                    'code' => Schema::string()->since(ApiRevision::Genesis),
                ]),
                'code' => Schema::string()->since(ApiRevision::Genesis),
                'old' => Schema::object([
                    'inner' => Schema::string()->was(ApiRevision::Genesis, name: 'former'),
                ])->until(ApiRevision::Genesis),
                'address' => Schema::object([
                    'zip' => Schema::string()->was(ApiRevision::Genesis, name: 'postcode'),
                ]),
                'lines' => Schema::list(Schema::object([
                    'sku' => Schema::string()->since(ApiRevision::Genesis),
                ])),
                'kept' => Schema::string()->was(ApiRevision::Winter25, name: 'kept_v1'),
                'later' => Schema::string()->since(ApiRevision::Winter25),
                ...Schema::since(ApiRevision::Genesis, ['grouped' => Schema::string()]),
            ],
        };
    }
}
