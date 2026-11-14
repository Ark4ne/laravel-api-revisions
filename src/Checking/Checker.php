<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Checking\Checks\BoundRepeated;
use Ark4ne\ApiRevisions\Checking\Checks\ComponentConflict;
use Ark4ne\ApiRevisions\Checking\Checks\ConversionLossy;
use Ark4ne\ApiRevisions\Checking\Checks\ConversionMissing;
use Ark4ne\ApiRevisions\Checking\Checks\EagerValue;
use Ark4ne\ApiRevisions\Checking\Checks\HistoryAfterUntil;
use Ark4ne\ApiRevisions\Checking\Checks\HistoryBeforeSince;
use Ark4ne\ApiRevisions\Checking\Checks\NameCollision;
use Ark4ne\ApiRevisions\Checking\Checks\OrderDuplicateCase;
use Ark4ne\ApiRevisions\Checking\Checks\OrderMissingCase;
use Ark4ne\ApiRevisions\Checking\Checks\RequiredSinceWithoutDefault;
use Ark4ne\ApiRevisions\Checking\Checks\SchemaOverride;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Ark4ne\ApiRevisions\Support\Diagnostic;
use Ark4ne\ApiRevisions\Support\Diagnostics;
use Ark4ne\ApiRevisions\Support\Severity;
use Throwable;

/**
 * Runs the registered checks (model.md §9): each on the revision enum first, then on every class the finder gives, in
 * its order. Every run compiles with a fresh `Shapes` over its `Compiler` (never a compiled file), shared by the checks
 * of the run. Before its checks, a class is built (its descriptors and its shapes at every revision, for every kind):
 * anything thrown is reported once, under the code of the `DefinitionException` or `definition-error`, and the class
 * is skipped (D39, D66). An invalid `order()` stops the run after the enum.
 */
final readonly class Checker
{
    /**
     * Codes reported by the checker itself.
     */
    public const string DEFINITION_ERROR = 'definition-error';

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
     * The checks of the core, one per code of model.md §9 that is not thrown when declared (the lock and the Laravel
     * ones excepted).
     *
     * @return list<Check>
     */
    public static function builtIn(): array
    {
        return [
            new OrderMissingCase(),
            new OrderDuplicateCase(),
            new BoundRepeated(),
            new HistoryBeforeSince(),
            new HistoryAfterUntil(),
            new ConversionMissing(),
            new ConversionLossy(),
            new NameCollision(),
            new RequiredSinceWithoutDefault(),
            new EagerValue(),
            new SchemaOverride(),
            new ComponentConflict(),
        ];
    }

    /**
     * Every diagnostic of every check on every target.
     */
    public function run(): Diagnostics
    {
        $diagnostics = new Diagnostics();
        $shapes = new Shapes($this->compiler);
        $classes = $this->finder->find();
        $this->runChecks(new CheckContext($this->revisions, null, $this->compiler, $shapes, $diagnostics, $classes));
        if (!$this->orderIsValid($diagnostics)) {
            return $diagnostics;
        }
        foreach ($classes as $class) {
            $context = new CheckContext($this->revisions, $class, $this->compiler, $shapes, $diagnostics, $classes);
            if ($this->builds($context, $diagnostics)) {
                $this->runChecks($context);
            }
        }

        return $diagnostics;
    }

    private function runChecks(CheckContext $context): void
    {
        foreach ($this->checks as $check) {
            $check->run($context);
        }
    }

    /**
     * Whether `order()` is valid; when it is not and neither `order-missing-case` nor `order-duplicate-case` reported
     * it, a `definition-error` of the enum.
     */
    private function orderIsValid(Diagnostics $diagnostics): bool
    {
        try {
            RevisionOrder::latest($this->revisions);

            return true;
        } catch (Throwable $error) {
            $reported = array_filter(
                $diagnostics->all(),
                static fn(Diagnostic $diagnostic): bool => in_array($diagnostic->code, [OrderMissingCase::CODE, OrderDuplicateCase::CODE], true),
            );
            if ($reported === []) {
                $diagnostics->add(new Diagnostic(self::DEFINITION_ERROR, Severity::Error, $this->revisions, null, null, $error->getMessage()));
            }

            return false;
        }
    }

    /**
     * Builds the class of `$context` (its descriptors and its shapes at every revision, for every kind); whatever it
     * throws is reported once, under the code of a `DefinitionException`, else `definition-error` (D39, D66).
     */
    private function builds(CheckContext $context, Diagnostics $diagnostics): bool
    {
        try {
            foreach ($context->kinds() as $kind) {
                $context->descriptors($kind);
                foreach ($context->order() as $revision) {
                    $context->shape($kind, $revision);
                }
            }

            return true;
        } catch (Throwable $error) {
            $code = $error instanceof DefinitionException ? $error->checkCode : self::DEFINITION_ERROR;
            $diagnostics->add(new Diagnostic($code, Severity::Error, (string) $context->class, null, null, $error->getMessage()));

            return false;
        }
    }
}
