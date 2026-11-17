<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Console;

use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Compilation\CompiledShapes;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Schema\ClassLifetime;
use Illuminate\Console\Command;

/**
 * `revision:cache`: compiles every declaration kind of every discovered class at every revision of the
 * enum and writes the compiled shapes file (`cache_path`), run by `php artisan optimize`. A declaration that does not
 * compile, or a `discovery.classes` entry that is not a concrete `Revisioned` class, fails the command and leaves the previous file.
 */
final class CacheCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'revision:cache';

    /**
     * @var string
     */
    protected $description = 'Compile the shapes of every revisioned class into a file for faster loading';

    public function handle(RevisionsConfig $config, Configuration $configuration, ClassFinder $finder): int
    {
        $compiler = new Compiler($configuration);
        $classes = $finder->find();
        $shapes = [];
        foreach ($classes as $class) {
            foreach ($class::declarationKinds() as $kind) {
                foreach ($config->enum::order() as $revision) {
                    if (!ClassLifetime::exists($class, $revision)) {
                        continue;
                    }
                    $shapes[] = $compiler->compile($class, $kind, $revision);
                }
            }
        }
        CompiledShapes::write($config->cachePath, $shapes);

        $this->components->info(sprintf('%d revision shapes of %d classes cached.', count($shapes), count($classes)));

        return self::SUCCESS;
    }
}
