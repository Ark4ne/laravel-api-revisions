<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use RuntimeException;
use Test\Fixtures\ApiRevision;

/**
 * A declaration counting how many times it is described (compiled), failing while `$fail` is set.
 */
final class CountingDeclaration implements Revisioned
{
    public static int $described = 0;

    public static bool $fail = false;

    public static function declarationKinds(): array
    {
        return [DeclarationKind::RequestBody, DeclarationKind::RequestQuery, DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        ++self::$described;
        if (self::$fail) {
            throw new RuntimeException('The declaration fails.');
        }

        return [
            'title' => Schema::string()->was(ApiRevision::Winter25, name: 'name'),
        ];
    }
}
