<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel;

use Ark4ne\ApiRevisions\Compilation\CompiledShapes;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Laravel\Console\CacheCommand;
use Ark4ne\ApiRevisions\Laravel\Console\ClearCommand;
use Ark4ne\ApiRevisions\Projection\LenientValues;
use Ark4ne\ApiRevisions\Projection\StrictValues;
use Ark4ne\ApiRevisions\Projection\ValueErrorHandler;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the library in a Laravel application (auto-discovered, model.md §8, §10): `config/revisions.php` merged and
 * publishable (`revisions-config`), read lazily as a `RevisionsConfig`; the core `Configuration` and the `Shapes`
 * compiled with it, one per application, reading the compiled file of `revision:cache` first; the `RevisionScope` of the running context as a `scoped` binding (reset on
 * every request, Octane included; the scope provider reads the current container), its base the configured fixed
 * default (D31); the `ValueErrorHandler` from
 * `strict_values`, a lenient one reporting each error once per class and field and request; in the console, the commands
 * `revision:cache` / `revision:clear`, run by `optimize` / `optimize:clear`. The middleware, the route macro and the
 * other commands are added by their stories (US 8.2 onwards).
 */
final class RevisionsServiceProvider extends ServiceProvider
{
    private const string CONFIG = __DIR__ . '/../../config/revisions.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'revisions');

        $this->app->singleton(RevisionsConfig::class, static function (Container $app): RevisionsConfig {
            $config = $app->make(Repository::class);
            $revisions = $config->get('revisions');

            return RevisionsConfig::fromArray(is_array($revisions) ? $revisions : [], $config->get('app.debug') === true);
        });
        $this->app->singleton(Configuration::class, static fn(Container $app): Configuration => $app->make(RevisionsConfig::class)->configuration);
        $this->app->singleton(Shapes::class, static fn(Container $app): Shapes => new Shapes(
            new Compiler($app->make(Configuration::class)),
            CompiledShapes::load($app->make(RevisionsConfig::class)->cachePath),
        ));
        $this->app->scoped(RevisionScope::class, static function (Container $app): RevisionScope {
            $scope = new RevisionScope();
            $scope->setBase($app->make(RevisionsConfig::class)->fixedDefault());

            return $scope;
        });
        $this->app->scoped(ValueErrorHandler::class, static function (Container $app): ValueErrorHandler {
            if ($app->make(Configuration::class)->strictValues) {
                return new StrictValues();
            }
            $reported = [];

            return new LenientValues(static function (ValueException $error) use ($app, &$reported): void {
                $key = "{$error->class}@{$error->field}";
                if (!($reported[$key] ?? false)) {
                    $reported[$key] = true;
                    $app->make(ExceptionHandler::class)->report($error);
                }
            });
        });
        // the container of the call, never the one of the boot: Octane runs each request in a clone of the application
        RevisionScope::provider(static fn(): RevisionScope => ContainerInstance::getInstance()->make(RevisionScope::class));
    }

    public function boot(): void
    {
        $this->publishes([self::CONFIG => $this->app->configPath('revisions.php')], 'revisions-config');

        if ($this->app->runningInConsole()) {
            $this->commands([CacheCommand::class, ClearCommand::class]);
            $this->optimizes(optimize: 'revision:cache', clear: 'revision:clear', key: 'revisions');
        }
    }
}
