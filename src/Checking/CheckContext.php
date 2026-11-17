<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Ark4ne\ApiRevisions\Schema\ClassLifetime;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Support\Diagnostic;
use Ark4ne\ApiRevisions\Support\Diagnostics;
use Ark4ne\ApiRevisions\Support\Severity;

/**
 * What one check sees of one target: the revision enum, the declaring class checked (`null` when the target is the
 * enum itself), its declarations (head descriptors and shapes, compiled once per run and shared by the checks) and the
 * diagnostics it adds, each naming the target class (the enum when `$class` is `null`). Built by the `Checker`, after
 * the class was built without error.
 */
final class CheckContext
{
    /**
     * @var array<string, array<string, Descriptor<mixed>>> the head descriptors read, by kind
     */
    private array $descriptors = [];

    /**
     * @param class-string<Revision> $revisions
     * @param class-string<Revisioned>|null $class
     * @param list<class-string<Revisioned>> $classes every class the run checks (the targets after the enum)
     * @param bool $subset whether `$classes` are only some of the application's (`Checker::forClasses()`, model.md
     *                     D91): a check needing every class (a locked class gone) skips what it cannot tell
     */
    public function __construct(
        public readonly string $revisions,
        public readonly ?string $class,
        private readonly Compiler $compiler,
        private readonly Shapes $shapes,
        private readonly Diagnostics $diagnostics,
        public readonly array $classes = [],
        public readonly bool $subset = false,
    ) {}

    /**
     * The revisions of the enum, oldest first; for a class, those it exists in (`HasLifetime`, model.md D81).
     *
     * @return list<Revision>
     */
    public function order(): array
    {
        $order = RevisionOrder::between(RevisionOrder::oldest($this->revisions), RevisionOrder::latest($this->revisions));
        $class = $this->class;

        return $class === null
            ? $order
            : array_values(array_filter($order, static fn(Revision $revision): bool => ClassLifetime::exists($class, $revision)));
    }

    /**
     * The declaration kinds of the checked class; none for the enum.
     *
     * @return list<DeclarationKind>
     */
    public function kinds(): array
    {
        return $this->class === null ? [] : $this->class::declarationKinds();
    }

    /**
     * The head descriptors of the declaration `$kind` of the checked class, by key, described once;
     * `DefinitionException::noShapeForRevisions()` when the target is the enum.
     *
     * @return array<string, Descriptor<mixed>>
     */
    public function descriptors(DeclarationKind $kind): array
    {
        if ($this->class === null) {
            throw DefinitionException::noShapeForRevisions($this->revisions);
        }

        return $this->descriptors[$kind->value] ??= $this->compiler->describe($this->class, $kind);
    }

    /**
     * The shape of the declaration `$kind` of the checked class at `$revision`;
     * `DefinitionException::noShapeForRevisions()` when the target is the enum.
     */
    public function shape(DeclarationKind $kind, Revision $revision): Shape
    {
        if ($this->class === null) {
            throw DefinitionException::noShapeForRevisions($this->revisions);
        }

        return $this->shapes->for($this->class, $kind, $revision);
    }

    /**
     * The case of the enum whose backed value is `$value` as a string, `null` when none is.
     */
    public function revision(string $value): ?Revision
    {
        foreach ($this->revisions::cases() as $case) {
            if ((string) $case->value === $value) {
                return $case;
            }
        }

        return null;
    }

    /**
     * The JSON Schema of the declaration `$kind` of the checked class at `$revision`, exported from the shapes of the
     * run (US 10.1); `DefinitionException::noShapeForRevisions()` when the target is the enum.
     *
     * @return array<string, mixed>
     */
    public function export(DeclarationKind $kind, Revision $revision): array
    {
        return (new JsonSchemaExporter($this->shapes))->visit($this->shape($kind, $revision));
    }

    /**
     * The registry the shapes were planned against.
     */
    public function converters(): Converters
    {
        return $this->compiler->converters();
    }

    /**
     * Adds an error of `$code`, on `$field` (a head key, dotted below the top level) and `$revision` when given.
     */
    public function error(string $code, string $message, ?string $field = null, ?Revision $revision = null): void
    {
        $this->add(Severity::Error, $code, $message, $field, $revision);
    }

    /**
     * Adds a warning of `$code`, on `$field` (a head key, dotted below the top level) and `$revision` when given.
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
