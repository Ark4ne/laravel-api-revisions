<?php

declare(strict_types=1);

namespace {
    use Ark4ne\ApiRevisions\Schema\DeclarationKind;
    use Ark4ne\ApiRevisions\Schema\Revisioned;
    use Ark4ne\ApiRevisions\Schema\Schema;

    /**
     * A declaring class in the global namespace block: discovered without a leading backslash.
     */
    final class GlobalDeclaration implements Revisioned
    {
        public static function declarationKinds(): array
        {
            return [DeclarationKind::Response];
        }

        public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
        {
            return ['id' => Schema::int()];
        }
    }
}
