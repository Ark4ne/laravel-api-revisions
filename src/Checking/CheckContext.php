<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Support\Diagnostic;
use Ark4ne\ApiRevisions\Support\Diagnostics;
use Ark4ne\ApiRevisions\Support\Severity;

/**
 * What one check sees of one target: the revision enum, the declaring class checked (`null` when the target is the
 * enum itself), its shapes (compiled once per run, shared by the checks) and the diagnostics it adds, each naming the
 * target class (the enum when `$class` is `null`).
 */
final readonly class CheckContext
{
    /**
     * @param class-string<Revision> $revisions
     * @param class-string<Revisioned>|null $class
     */
    public function __construct(
        public string $revisions,
        public ?string $class,
        private Shapes $shapes,
        private Diagnostics $diagnostics,
    ) {}

    /**
     * The shape of the declaration `$kind` of the checked class at `$revision`; `DefinitionException::noShapeForRevisions()`
     * when the target is the enum.
     */
    public function shape(DeclarationKind $kind, Revision $revision): Shape
    {
        if ($this->class === null) {
            throw DefinitionException::noShapeForRevisions($this->revisions);
        }

        return $this->shapes->for($this->class, $kind, $revision);
    }

    /**
     * Adds an error of `$code`, on `$field` (a head key) and `$revision` when given.
     */
    public function error(string $code, string $message, ?string $field = null, ?Revision $revision = null): void
    {
        $this->add(Severity::Error, $code, $message, $field, $revision);
    }

    /**
     * Adds a warning of `$code`, on `$field` (a head key) and `$revision` when given.
     */
    public function warning(string $code, string $message, ?string $field = null, ?Revision $revision = null): void
    {
        $this->add(Severity::Warning, $code, $message, $field, $revision);
    }

    /**
     * `DefinitionException::foreignRevision()` for a revision of another enum: a programming error of the check.
     */
    private function add(Severity $severity, string $code, string $message, ?string $field, ?Revision $revision): void
    {
        if ($revision !== null && !$revision instanceof $this->revisions) {
            throw DefinitionException::foreignRevision($revision, $this->revisions);
        }
        $this->diagnostics->add(new Diagnostic(
            $code,
            $severity,
            $this->class ?? $this->revisions,
            $field,
            $revision === null ? null : (string) $revision->value,
            $message,
        ));
    }
}
