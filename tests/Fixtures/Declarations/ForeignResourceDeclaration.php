<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use stdClass;

/**
 * A response whose custom descriptor references, as a nested resource, a class that is not `Revisioned`.
 */
final class ForeignResourceDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return [
            'owner' => new class extends Descriptor {
                public function normalize(mixed $value): mixed
                {
                    return $value;
                }

                protected function type(): TypeDefinition
                {
                    return TypeDefinition::of(Kind::Object)->withResource(stdClass::class);
                }
            },
        ];
    }
}
