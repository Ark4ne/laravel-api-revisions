<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Console;

use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Illuminate\Console\Command;
use ReflectionClass;

/**
 * `revision:export` (model.md §11, D67): the JSON Schema of every discovered class, for each of its declaration kinds,
 * at every revision, printed as `{revision: {class: {kind: schema}}}`; `--revision=` (a backed value) and `--class=`
 * narrow it, and the schema alone is printed when one revision, one class and a single kind remain.
 */
final class ExportCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'revision:export
        {--revision= : the backed value of one revision}
        {--class= : one revisioned class}';

    /**
     * @var string
     */
    protected $description = 'Export the JSON Schema of the revisioned declarations';

    public function handle(RevisionsConfig $config, ClassFinder $finder, JsonSchemaExporter $exporter): int
    {
        $revisions = $config->enum::order();
        $revision = $this->option('revision');
        if (is_string($revision)) {
            $revisions = array_values(array_filter($revisions, static fn(Revision $case): bool => (string) $case->value === $revision));
            if ($revisions === []) {
                $accepted = implode(', ', array_map(static fn(Revision $case): string => (string) $case->value, $config->enum::order()));
                $this->components->error(sprintf('Unknown revision "%s": %s.', $revision, $accepted));

                return self::FAILURE;
            }
        }
        $class = $this->option('class');
        if (is_string($class)) {
            if (!is_subclass_of($class, Revisioned::class) || (new ReflectionClass($class))->isAbstract()) {
                $this->components->error(sprintf('%s is not a class implementing %s.', $class, Revisioned::class));

                return self::FAILURE;
            }
            $classes = [$class];
        } else {
            $classes = $finder->find();
        }

        try {
            return $this->print($exporter, $revisions, $classes);
        } catch (DefinitionException $error) {
            // a declaration the check reports (`revision:check`)
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param list<Revision> $revisions
     * @param list<class-string<Revisioned>> $classes
     */
    private function print(JsonSchemaExporter $exporter, array $revisions, array $classes): int
    {
        if (count($revisions) === 1 && count($classes) === 1 && count($classes[0]::declarationKinds()) === 1) {
            $this->line(json_encode(JsonSchemaExporter::objects($exporter->export($classes[0], $classes[0]::declarationKinds()[0], $revisions[0])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $export = [];
        foreach ($revisions as $case) {
            foreach ($classes as $declaring) {
                foreach ($declaring::declarationKinds() as $kind) {
                    $export[(string) $case->value][$declaring][$kind->value] = JsonSchemaExporter::objects($exporter->export($declaring, $kind, $case));
                }
            }
        }
        $this->line($export === [] ? '{}' : json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
