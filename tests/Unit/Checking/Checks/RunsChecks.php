<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Closure;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CheckedDeclaration;

/**
 * Runs one check on `CheckedDeclaration` declared by a closure, and reads its diagnostics as tuples.
 */
trait RunsChecks
{
    #[\PHPUnit\Framework\Attributes\Before]
    protected function resetCheckedDeclaration(): void
    {
        CheckedDeclaration::reset();
    }

    /**
     * @param Closure(DeclarationKind): array<array-key, mixed> $describe
     * @param non-empty-list<DeclarationKind> $kinds
     *
     * @return list<array{string, string, ?string, ?string, string}> code, severity, field, revision, message
     */
    private static function check(Check $check, Closure $describe, array $kinds = [DeclarationKind::Response], Compiler $compiler = new Compiler()): array
    {
        CheckedDeclaration::$kinds = $kinds;
        CheckedDeclaration::$describe = $describe;
        $finder = new readonly class implements ClassFinder {
            public function find(): array
            {
                return [CheckedDeclaration::class];
            }
        };
        $found = [];
        foreach ((new Checker(ApiRevision::class, $finder, [$check], $compiler))->run() as $diagnostic) {
            self::assertSame(CheckedDeclaration::class, $diagnostic->class);
            $found[] = [$diagnostic->code, $diagnostic->severity->value, $diagnostic->field, $diagnostic->revision, $diagnostic->message];
        }

        return $found;
    }
}
