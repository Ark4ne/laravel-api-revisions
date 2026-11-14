<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Console;

use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Checking\DeclarationLocator;
use Ark4ne\ApiRevisions\Checking\Prune;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;

/**
 * `revision:prune` (model.md D73): what retiring the oldest revision makes dead in the discovered classes, each
 * declaration with its file (relative to the application) and line. Informative: it always succeeds, a class that
 * does not build is skipped with a warning (`revision:check` reports it).
 */
final class PruneCommand extends Command
{
    /**
     * The methods declaring fields: the core's, a `RevisionedResource`'s, a `RevisionedRequest`'s.
     */
    public const array METHODS = ['describeRevisioned', 'toArray', 'body', 'queryParameters'];

    /**
     * @var string
     */
    protected $signature = 'revision:prune';

    /**
     * @var string
     */
    protected $description = 'List the declarations that serve only the oldest revision';

    public function handle(RevisionsConfig $config, Configuration $configuration, ClassFinder $finder, Application $app): int
    {
        $report = (new Prune($config->enum, new Compiler($configuration), new DeclarationLocator(self::METHODS)))->run($finder->find());
        foreach ($report->failures as $class => $message) {
            $this->components->warn("{$class} skipped, it does not build: {$message}");
        }
        if ($report->candidates === []) {
            $this->components->info('Nothing to prune.');

            return self::SUCCESS;
        }
        $count = count($report->candidates);
        $this->line(sprintf('Retiring %s (the oldest revision) makes %d %s dead:', $report->revision->name, $count, $count === 1 ? 'declaration' : 'declarations'));
        foreach ($report->candidates as $candidate) {
            $this->line('- ' . $candidate->describe($app->basePath()));
        }

        return self::SUCCESS;
    }
}
