<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\HasLifetime;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Closure;

/**
 * A declaration the check tests set: its kinds, a closure giving its descriptors per kind and its lifetime (reset by the
 * tests).
 */
final class CheckedDeclaration implements Revisioned, HasLifetime
{
    /**
     * @var non-empty-list<DeclarationKind>
     */
    public static array $kinds = [DeclarationKind::Response];

    /**
     * @var (Closure(DeclarationKind): array<array-key, mixed>)|null
     */
    public static ?Closure $describe = null;

    public static ?Lifetime $lifetime = null;

    public static function reset(): void
    {
        self::$kinds = [DeclarationKind::Response];
        self::$describe = null;
        self::$lifetime = null;
    }

    public static function revisionedLifetime(): Lifetime
    {
        return self::$lifetime ?? Lifetime::always();
    }

    public static function declarationKinds(): array
    {
        return self::$kinds;
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        /** @var array<string, \Ark4ne\ApiRevisions\Schema\Descriptor<mixed>> $descriptors checked by the compiler */
        $descriptors = self::$describe === null ? [] : (self::$describe)($kind);

        return $descriptors;
    }
}
