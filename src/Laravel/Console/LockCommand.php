<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Console;

use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Checking\Lock;
use Ark4ne\ApiRevisions\Checking\LockedRevision;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Illuminate\Console\Command;
use Throwable;

/**
 * `revision:lock` (model.md §9, D11, D21, D69): writes the snapshot of every revision but the head that has no lock
 * yet, never rewriting a published one; `--revision=` (a backed value) (re)writes that one, the head included. The
 * shapes are compiled anew (never the compiled shapes file).
 */
final class LockCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'revision:lock
        {--revision= : the backed value of one revision to (re)lock}';

    /**
     * @var string
     */
    protected $description = 'Lock the structure of the published revisions';

    public function handle(RevisionsConfig $config, Configuration $configuration, ClassFinder $finder, Lock $lock): int
    {
        if (!$config->revisioned) {
            // no enum configured (D80): the one implicit revision is the head, never locked
            $this->components->info('No revision enum configured: nothing to lock.');

            return self::SUCCESS;
        }
        $order = $config->enum::order();
        $revision = $this->option('revision');
        if (is_string($revision)) {
            $revisions = array_values(array_filter($order, static fn(Revision $case): bool => (string) $case->value === $revision));
            if ($revisions === []) {
                $accepted = implode(', ', array_map(static fn(Revision $case): string => (string) $case->value, $order));
                $this->components->error(sprintf('Unknown revision "%s": %s.', $revision, $accepted));

                return self::FAILURE;
            }
        } else {
            $revisions = array_values(array_filter(array_slice($order, 0, -1), static fn(Revision $case): bool => !$lock->has($case)));
            if ($revisions === []) {
                $this->components->info('Every revision but the head is already locked.');

                return self::SUCCESS;
            }
        }

        $exporter = new JsonSchemaExporter(new Shapes(new Compiler($configuration)));
        $classes = $finder->find();
        try {
            // every snapshot captured before any is written: a declaration error leaves the lock as it was
            $snapshots = array_map(static fn(Revision $case): LockedRevision => Lock::capture($case, $classes, $exporter), $revisions);
            foreach ($snapshots as $snapshot) {
                $lock->write($snapshot);
            }
        } catch (Throwable $error) {
            // a declaration the check reports (`revision:check`, D66), or a lock file that cannot be written
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }
        $this->components->info(sprintf('Locked %s.', implode(', ', array_map(static fn(Revision $case): string => (string) $case->value, $revisions))));

        return self::SUCCESS;
    }
}
