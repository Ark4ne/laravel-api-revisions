<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Console;

use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Illuminate\Console\Command;

/**
 * `revision:clear`: removes the compiled shapes file (`cache_path`), run by `php artisan optimize:clear`.
 */
final class ClearCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'revision:clear';

    /**
     * @var string
     */
    protected $description = 'Remove the compiled shapes file';

    public function handle(RevisionsConfig $config): int
    {
        if (is_file($config->cachePath)) {
            unlink($config->cachePath);
        }

        $this->components->info('Compiled revision shapes cleared.');

        return self::SUCCESS;
    }
}
