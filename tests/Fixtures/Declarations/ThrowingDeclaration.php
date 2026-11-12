<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use LogicException;
use Test\Fixtures\ApiRevision;

/**
 * A declaration whose source, condition and conversions throw when run: compiling it proves none runs.
 */
final class ThrowingDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $probe): array
    {
        $ran = static fn(): never => throw new LogicException('Ran at compilation.');

        return [
            'total' => Schema::int($ran)
                ->when($ran)
                ->was(ApiRevision::Winter25, schema: Schema::string(), down: $ran, up: $ran),
        ];
    }
}
