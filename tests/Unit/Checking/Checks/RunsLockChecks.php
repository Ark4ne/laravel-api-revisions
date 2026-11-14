<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Checking\Lock;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use PHPUnit\Framework\Attributes\After;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\ListedClasses;
use Test\TemporaryDirectory;

/**
 * Locks revisions of some classes in a temporary directory, then runs one lock check on them, reading its diagnostics
 * as tuples.
 */
trait RunsLockChecks
{
    use TemporaryDirectory;

    #[After]
    protected function resetLockedDeclaration(): void
    {
        CheckedDeclaration::reset();
    }

    private function lock(): Lock
    {
        return new Lock($this->temporary);
    }

    /**
     * @param list<class-string<Revisioned>> $classes
     */
    private function lockRevisions(array $classes, ApiRevision ...$revisions): void
    {
        foreach ($revisions as $revision) {
            $this->lock()->write(Lock::capture($revision, $classes, new JsonSchemaExporter(new Shapes())));
        }
    }

    /**
     * @param list<class-string<Revisioned>> $classes
     *
     * @return list<array{string, string, ?string, ?string, string}> code, class, field, revision, message
     */
    private static function runCheck(Check $check, array $classes): array
    {
        $found = [];
        foreach ((new Checker(ApiRevision::class, new ListedClasses($classes), [$check]))->run() as $diagnostic) {
            self::assertSame('error', $diagnostic->severity->value);
            $found[] = [$diagnostic->code, $diagnostic->class, $diagnostic->field, $diagnostic->revision, $diagnostic->message];
        }

        return $found;
    }
}
