<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Throwable;

/**
 * What retiring the oldest revision `P` of `$revisions` makes dead: per class and declaration kind, by
 * head key path, an `until(P)` (the field, its children and history then left out), a `was(P)`, a `since(P)`.
 */
final readonly class Prune
{
    /**
     * @param class-string<Revision> $revisions
     */
    public function __construct(
        private string $revisions,
        private Compiler $compiler = new Compiler(),
        private DeclarationLocator $locator = new DeclarationLocator(),
    ) {}

    /**
     * @param list<class-string<Revisioned>> $classes
     */
    public function run(array $classes): PruneReport
    {
        $oldest = RevisionOrder::oldest($this->revisions);
        $candidates = [];
        $failures = [];
        foreach ($classes as $class) {
            try {
                $found = [];
                foreach ($class::declarationKinds() as $kind) {
                    foreach ($this->dead($class, $kind, $oldest) as $candidate) {
                        $found[] = $candidate;
                    }
                }
                array_push($candidates, ...$found);
            } catch (Throwable $error) {
                // the declarations are application code: whatever they throw is reported, the other classes go on
                $failures[$class] = $error->getMessage();
            }
        }

        return new PruneReport($oldest, $candidates, $failures);
    }

    /**
     * The dead declarations of the declaration `$kind` of `$class`, parents before their children.
     *
     * @param class-string<Revisioned> $class
     *
     * @return list<PruneCandidate>
     */
    private function dead(string $class, DeclarationKind $kind, Revision $oldest): array
    {
        $candidates = [];
        $removed = [];
        foreach (Fields::descriptors($this->compiler->describe($class, $kind)) as $path => $descriptor) {
            foreach ($removed as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    continue 2;
                }
            }
            $history = $descriptor->history();
            if ($history->lifetime->until === $oldest) {
                // the whole field goes: its children, its items and its `was()` with it
                $removed[] = "{$path}.";
                $candidates[] = $this->candidate($class, $kind, $path, PrunedDeclaration::Until, $oldest);

                continue;
            }
            foreach ($history->changes as $change) {
                if ($change->revision === $oldest) {
                    $candidates[] = $this->candidate($class, $kind, $path, PrunedDeclaration::Was, $oldest);
                }
            }
            if ($history->lifetime->since === $oldest) {
                $candidates[] = $this->candidate($class, $kind, $path, PrunedDeclaration::Since, $oldest);
            }
        }

        return $candidates;
    }

    /**
     * @param class-string<Revisioned> $class
     */
    private function candidate(string $class, DeclarationKind $kind, string $path, PrunedDeclaration $declaration, Revision $oldest): PruneCandidate
    {
        ['file' => $file, 'line' => $line] = $this->locator->locate($class, $path, $oldest);

        return new PruneCandidate($class, $kind, $path, $declaration, $oldest, $file, $line);
    }
}
