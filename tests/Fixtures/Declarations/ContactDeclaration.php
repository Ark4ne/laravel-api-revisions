<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use DateTimeImmutable;
use Test\Fixtures\ApiRevision;

/**
 * A request resource nested in another (model.md D49) whose field converts up: a date sent as a free string before
 * `Spring26`.
 */
final class ContactDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::RequestBody];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return [
            'since' => Schema::date()->was(ApiRevision::Winter25, schema: Schema::string(), up: static fn(string $day): DateTimeImmutable => new DateTimeImmutable($day)),
        ];
    }
}
