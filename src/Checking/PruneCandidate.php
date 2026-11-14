<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;

/**
 * One declaration made dead by retiring the oldest revision (model.md D73), located when reflection can tell.
 */
final readonly class PruneCandidate
{
    /**
     * @param class-string<Revisioned> $class
     * @param string $path the head key path of the field (`customer.name`, `lines.*.sku`)
     * @param string|null $file the file of the class, `null` when reflection cannot tell
     * @param int|null $line the line of the field key, else of its declaring method, `null` when unknown
     */
    public function __construct(
        public string $class,
        public DeclarationKind $kind,
        public string $path,
        public PrunedDeclaration $declaration,
        public Revision $revision,
        public ?string $file = null,
        public ?int $line = null,
    ) {}

    /**
     * One line: the class, the kind, the field, what to delete and where (the file relative to `$base` when under it).
     */
    public function describe(?string $base = null): string
    {
        $file = $this->file;
        if ($file !== null && $base !== null) {
            $base = rtrim($base, '/') . '/';
            $file = str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
        }
        $where = match (true) {
            $file === null => '',
            $this->line === null => " ({$file})",
            default => " ({$file}:{$this->line})",
        };

        return sprintf(
            "%s %s '%s': %s(%s), %s%s",
            $this->class,
            $this->kind->value,
            $this->path,
            $this->declaration->value,
            $this->revision->name,
            $this->declaration->advice(),
            $where,
        );
    }
}
