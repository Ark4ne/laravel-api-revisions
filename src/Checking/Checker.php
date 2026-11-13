<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Support\Diagnostics;

/**
 * Runs the registered checks (model.md §9): each on the revision enum first, then on every class the finder gives, in
 * its order. Every run compiles with a fresh `Shapes` over its `Compiler` (never a compiled file), shared by the checks
 * of the run.
 */
final readonly class Checker
{
    /**
     * @param class-string<Revision> $revisions
     * @param list<Check> $checks
     */
    public function __construct(
        private string $revisions,
        private ClassFinder $finder,
        private array $checks,
        private Compiler $compiler = new Compiler(),
    ) {}

    /**
     * Every diagnostic of every check on every target.
     */
    public function run(): Diagnostics
    {
        $diagnostics = new Diagnostics();
        $shapes = new Shapes($this->compiler);
        foreach ([null, ...$this->finder->find()] as $class) {
            $context = new CheckContext($this->revisions, $class, $shapes, $diagnostics);
            foreach ($this->checks as $check) {
                $check->run($context);
            }
        }

        return $diagnostics;
    }
}
