<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Console;

use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Export\Changelog;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Illuminate\Console\Command;
use Throwable;

/**
 * `revision:changelog` (model.md §11, D71): the Markdown changelog of every discovered class, from the revision after
 * `--from` (the oldest by default, excluded) to `--to` (the head by default, included), newest first.
 */
final class ChangelogCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'revision:changelog
        {--from= : the backed value of the base revision, excluded}
        {--to= : the backed value of the last revision, included}';

    /**
     * @var string
     */
    protected $description = 'Print the changelog of the revisions in Markdown';

    public function handle(RevisionsConfig $config, Configuration $configuration, ClassFinder $finder): int
    {
        $order = $config->enum::order();
        if (count($order) === 1 && $this->option('from') === null && $this->option('to') === null) {
            // one revision (an enum of one case, or none configured, D80): there is no change to list
            $this->components->info('One revision only: no change to list.');

            return self::SUCCESS;
        }
        $from = $this->revision($order, 'from', RevisionOrder::oldest($config->enum));
        $to = $this->revision($order, 'to', RevisionOrder::latest($config->enum));
        if ($from === null || $to === null) {
            return self::FAILURE;
        }
        if (RevisionOrder::compare($from, $to) >= 0) {
            $this->components->error(sprintf('--from (%s) must be older than --to (%s).', $from->value, $to->value));

            return self::FAILURE;
        }
        try {
            $markdown = (new Changelog(new Shapes(new Compiler($configuration))))->markdown($finder->find(), $from, $to);
        } catch (Throwable $error) {
            // the declarations are application code: whatever they throw is reported as `revision:check` does (D66)
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }
        foreach (explode("\n", rtrim($markdown, "\n")) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * The revision the option `$name` gives, `$default` without it; `null` (and an error) for an unknown value.
     *
     * @param list<Revision> $order
     */
    private function revision(array $order, string $name, Revision $default): ?Revision
    {
        $value = $this->option($name);
        if (!is_string($value)) {
            return $default;
        }
        foreach ($order as $case) {
            if ((string) $case->value === $value) {
                return $case;
            }
        }
        $accepted = implode(', ', array_map(static fn(Revision $case): string => (string) $case->value, $order));
        $this->components->error(sprintf('Unknown revision "%s": %s.', $value, $accepted));

        return null;
    }
}
