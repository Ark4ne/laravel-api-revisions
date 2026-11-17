<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Routing;

use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Closure;
use Illuminate\Routing\Router;

/**
 * Path mode: the body of the route macro `Route::revisioned($routes)`. Groups `$routes` under the
 * prefix `{parameter}`, the `parameter` of the configured `PathResolver` (`ConfigurationException` without one),
 * constrained to the backed values of the enum, with the `revision` middleware (Laravel runs a middleware once per
 * route, so a group already holding it is fine).
 */
final class RevisionedRoutes
{
    /**
     * @param Closure(Router): void $routes
     */
    public static function group(Router $router, RevisionsConfig $config, Closure $routes): void
    {
        $parameter = $config->pathParameter();
        $values = array_map(static fn(Revision $revision): string => preg_quote((string) $revision->value, '/'), $config->enum::order());

        $router->group(['prefix' => "{{$parameter}}", 'where' => [$parameter => implode('|', $values)], 'middleware' => 'revision'], $routes);
    }
}
