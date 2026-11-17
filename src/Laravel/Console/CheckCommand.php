<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Console;

use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Support\Diagnostic;
use Illuminate\Console\Command;

/**
 * `revision:check`: runs the check on the revision enum and every discovered class (`discovery.paths`
 * plus `discovery.classes`) and prints its diagnostics, a table by default, JSON with `--format=json`; fails when one
 * is an error. `--list` prints the discovered classes only, so a forgotten path is visible (a JSON list with
 * `--format=json`). An invalid discovery entry fails the command with its reason. The classes are found by the
 * command (count, `--list`) and again by the `Checker`: two scans of the discovery paths, kept for a simple contract.
 */
final class CheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'revision:check
        {--format=table : table or json}
        {--list : print the discovered classes, without checking}';

    /**
     * @var string
     */
    protected $description = 'Check the consistency of the revisioned declarations';

    public function handle(Checker $checker, ClassFinder $finder): int
    {
        $format = $this->option('format');
        if ($format !== 'table' && $format !== 'json') {
            $this->components->error(sprintf('Unknown format "%s": use table or json.', is_string($format) ? $format : get_debug_type($format)));

            return self::FAILURE;
        }
        try {
            $classes = $finder->find();
        } catch (ConfigurationException $error) {
            // the discovery is configuration, not a declaration: reported, never thrown
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }
        if ($this->option('list') === true) {
            if ($format === 'json') {
                $this->line(json_encode($classes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            foreach ($classes as $class) {
                $this->line($class);
            }
            $this->components->info(self::count(count($classes), 'class', 'classes') . ' discovered.');

            return self::SUCCESS;
        }

        $diagnostics = $checker->run();
        $errors = count($diagnostics->errors());
        $warnings = count($diagnostics->warnings());
        if ($format === 'json') {
            $this->line(json_encode([
                'classes' => count($classes),
                'errors' => $errors,
                'warnings' => $warnings,
                'diagnostics' => array_map(static fn(Diagnostic $diagnostic): array => $diagnostic->toArray(), $diagnostics->all()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif (count($diagnostics) === 0) {
            $this->components->info(sprintf('The revisions of %s are consistent.', self::count(count($classes), 'class', 'classes')));
        } else {
            $this->table(
                ['Severity', 'Code', 'Class', 'Field', 'Revision', 'Message'],
                array_map(
                    static fn(Diagnostic $diagnostic): array => [
                        $diagnostic->severity->value,
                        $diagnostic->code,
                        $diagnostic->class,
                        $diagnostic->field ?? '',
                        $diagnostic->revision ?? '',
                        $diagnostic->message,
                    ],
                    $diagnostics->all(),
                ),
            );
            $summary = sprintf('%s, %s in %s.', self::count($errors, 'error', 'errors'), self::count($warnings, 'warning', 'warnings'), self::count(count($classes), 'class', 'classes'));
            $errors > 0 ? $this->components->error($summary) : $this->components->warn($summary);
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private static function count(int $count, string $one, string $many): string
    {
        return $count . ' ' . ($count === 1 ? $one : $many);
    }
}
