<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;

/**
 * An order line sent in a request body, nested as a resource: its quantity was a string an `up:` closure rejects when
 * it holds no digits.
 */
final class LineDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::RequestBody];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return [
            'quantity' => Schema::int()->was(
                ApiRevision::Genesis,
                schema: Schema::string(),
                name: 'count',
                up: static fn(string $count): int => ctype_digit($count) ? (int) $count : throw ConversionException::invalid('Not a quantity.'),
            ),
        ];
    }
}
