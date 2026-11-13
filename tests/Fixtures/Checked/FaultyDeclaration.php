<?php

declare(strict_types=1);

namespace Test\Fixtures\Checked;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;

/**
 * Discovered by `revision:check`: a `was()` older than its `since` (`history-before-since`) and a required field added
 * later without default (`required-since-without-default`, not reported under `missing_default: null`).
 */
final class FaultyDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::RequestBody];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return [
            'total' => Schema::int()->since(ApiRevision::Winter25, 0)->was(ApiRevision::Genesis, name: 'amount'),
            'priority' => Schema::int()->required()->since(ApiRevision::Atlas),
        ];
    }
}
