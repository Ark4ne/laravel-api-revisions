<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;

/**
 * A declaration reading its subject eagerly (an evaluated value instead of a lazy source): refused at compilation.
 */
final class EagerDeclaration implements Revisioned
{
    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        // @phpstan-ignore property.nonObject (the mistake this fixture stands for)
        $title = $subject->title;

        return ['title' => Schema::string(static fn(): mixed => $title)];
    }
}
