<?php

declare(strict_types=1);

namespace Test\Fixtures\Discovery;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;

/**
 * A class naming `Foo::class` and building an anonymous `Revisioned` class: neither is discovered (US 5.3).
 */
final class AnonymousFactory
{
    public static function make(): Revisioned
    {
        return new class implements Revisioned {
            public static function declarationKinds(): array
            {
                return [DeclarationKind::Response];
            }

            public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
            {
                return [];
            }
        };
    }

    public static function declaration(): string
    {
        return DiscoveredDeclaration::class;
    }
}
