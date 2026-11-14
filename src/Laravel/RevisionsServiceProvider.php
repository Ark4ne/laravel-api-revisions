<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel;

use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Checking\Checks\LockMismatch;
use Ark4ne\ApiRevisions\Checking\Checks\LockOrderChanged;
use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Checking\Lock;
use Ark4ne\ApiRevisions\Compilation\CompiledShapes;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Laravel\Checking\NativeCondition;
use Ark4ne\ApiRevisions\Laravel\Console\CacheCommand;
use Ark4ne\ApiRevisions\Laravel\Console\ChangelogCommand;
use Ark4ne\ApiRevisions\Laravel\Console\CheckCommand;
use Ark4ne\ApiRevisions\Laravel\Console\ClearCommand;
use Ark4ne\ApiRevisions\Laravel\Console\ExportCommand;
use Ark4ne\ApiRevisions\Laravel\Console\LockCommand;
use Ark4ne\ApiRevisions\Laravel\Console\PruneCommand;
use Ark4ne\ApiRevisions\Laravel\Discovery\PathClassFinder;
use Ark4ne\ApiRevisions\Laravel\Http\ResolveRevision;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\ResolverChain;
use Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver;
use Ark4ne\ApiRevisions\Laravel\Queue\CapturedRevisionHandler;
use Ark4ne\ApiRevisions\Laravel\Resources\EloquentSourceReader;
use Ark4ne\ApiRevisions\Laravel\Routing\RevisionedRoutes;
use Ark4ne\ApiRevisions\Laravel\Validation\RuleCompiler;
use Ark4ne\ApiRevisions\Projection\LenientValues;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Projection\StrictValues;
use Ark4ne\ApiRevisions\Projection\ValueErrorHandler;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use Ark4ne\ApiRevisions\Upgrade\Upgrader;
use Closure;
use Illuminate\Container\Container as ContainerInstance;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the library in a Laravel application (auto-discovered, model.md §8, §10): `config/revisions.php` merged and
 * publishable (`revisions-config`), read lazily as a `RevisionsConfig`; the core `Configuration` and the `Shapes`
 * compiled with it, one per application, reading the compiled file of `revision:cache` first; the `RevisionScope` of
 * the running context as a `scoped` binding (reset on every request, Octane included; the scope provider reads the
 * current container), its base the configured fixed default (D31); the `ValueErrorHandler` from `strict_values`, a
 * lenient one reporting each error once per class and field and request; the `ResolverChain` of `resolvers` and
 * `default` (`scoped`: a resolver may read request services); the `Projector` of `RevisionedResource` (`scoped`, with
 * the `EloquentSourceReader`, the `Shapes` and the `ValueErrorHandler`); the `RuleCompiler` and the `Upgrader` of the
 * request shapes (one per application, `RevisionedRequest`); in the console, the commands `revision:cache` /
 * `revision:clear`, run by `optimize` / `optimize:clear`; the payload hook of the jobs marked `CapturesRevision`
 * (`CapturedRevisionHandler`, D64); the middleware alias `revision` (`ResolveRevision`), unless the application defines one. The route
 * macro and the other commands are added by their stories (US 8.4 onwards).
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
        $this->app->bind(ClassFinder::class, static function (Container $app): ClassFinder {
            $config = $app->make(RevisionsConfig::class);

            return new PathClassFinder($config->discoveryPaths, $config->discoveryClasses);
        });
        $this->app->bind(Lock::class, static fn(Container $app): Lock => new Lock($app->make(RevisionsConfig::class)->lockPath));
        $this->app->bind(Checker::class, static fn(Container $app): Checker => new Checker(
            $app->make(RevisionsConfig::class)->enum,
            $app->make(ClassFinder::class),
            [...Checker::builtIn(), new NativeCondition(), new LockMismatch($app->make(Lock::class)), new LockOrderChanged($app->make(Lock::class))],
            new Compiler($app->make(Configuration::class)),
        ));
        $this->app->scoped(RevisionScope::class, static function (Container $app): RevisionScope {
            $scope = new RevisionScope();
            $scope->setBase($app->make(RevisionsConfig::class)->fixedDefault());

            return $scope;
        });
        $this->app->scoped(ResolverChain::class, static function (Container $app): ResolverChain {
            $config = $app->make(RevisionsConfig::class);
            $resolvers = [];
            foreach ($config->resolvers as [$class, $options]) {
                $resolvers[] = self::resolver($app, $class, $options);
            }

            return new ResolverChain($config->enum, $resolvers, $config->default instanceof Revision ? $config->default : self::resolver($app, $config->default));
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
        $this->app->singleton(RuleCompiler::class, static fn(Container $app): RuleCompiler => new RuleCompiler($app->make(Shapes::class)));
        $this->app->singleton(Upgrader::class, static fn(Container $app): Upgrader => new Upgrader($app->make(Configuration::class), shapes: $app->make(Shapes::class)));
        $this->app->scoped(Projector::class, static fn(Container $app): Projector => new Projector(
            $app->make(Configuration::class),
            reader: new EloquentSourceReader(),
            shapes: $app->make(Shapes::class),
            values: $app->make(ValueErrorHandler::class),
        ));
        // the container of the call, never the one of the boot: Octane runs each request in a clone of the application
        RevisionScope::provider(static fn(): RevisionScope => ContainerInstance::getInstance()->make(RevisionScope::class));
    }

    /**
     * The resolver `$class`, built by the container with its options as named arguments.
     *
     * @param class-string<RevisionResolver> $class
     * @param array<string, mixed> $options
     */
    private static function resolver(Container $app, string $class, array $options = []): RevisionResolver
    {
        $resolver = $app->make($class, $options);
        assert($resolver instanceof RevisionResolver);

        return $resolver;
    }

    public function boot(): void
    {
        $router = $this->app->make(Router::class);
        // a macro is bound to the router: its closure cannot be static, and reads the router's own container (the
        // macro is static, it outlives the application booting it)
        Router::macro('revisioned', function (Closure $routes): void {
            /** @var Router $this */
            RevisionedRoutes::group($this, $this->container->make(RevisionsConfig::class), $routes);
        });
        // an alias the application already defines under that name wins
        if (!array_key_exists('revision', $router->getMiddleware())) {
            $router->aliasMiddleware('revision', ResolveRevision::class);
        }
        // a payload hook is static, it outlives the application booting it: it reads the container of the dispatch
        Queue::createPayloadUsing(static fn(?string $connection, ?string $queue, array $payload): array => CapturedRevisionHandler::capture(
            $payload,
            static function (): ?Revision {
                $app = ContainerInstance::getInstance();
                $config = $app->make(RevisionsConfig::class);

                // no enum configured (D80): there is no revision to capture
                return $config->revisioned ? $app->make(RevisionScope::class)->current($config->enum) : null;
            },
        ));
        $this->publishes([self::CONFIG => $this->app->configPath('revisions.php')], 'revisions-config');

        if ($this->app->runningInConsole()) {
            $this->commands([CacheCommand::class, ChangelogCommand::class, CheckCommand::class, ClearCommand::class, ExportCommand::class, LockCommand::class, PruneCommand::class]);
            $this->optimizes(optimize: 'revision:cache', clear: 'revision:clear', key: 'revisions');
        }
    }
}
