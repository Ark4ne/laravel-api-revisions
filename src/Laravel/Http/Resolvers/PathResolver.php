<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Http\Resolvers;

use Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Illuminate\Http\Request;

/**
 * The revision named by the route parameter `$parameter` (`revision` by default, the prefix of path mode, US 8.4):
 * none outside a route or without the parameter, `UnknownRevisionException` when it names no revision of the
 * configured enum.
 */
final readonly class PathResolver implements RevisionResolver
{
    public function __construct(
        private RevisionsConfig $config,
        private string $parameter = 'revision',
    ) {}

    /**
     * The route parameter it reads (forgotten by the middleware, the prefix of `Route::revisioned()`).
     */
    public function parameter(): string
    {
        return $this->parameter;
    }

    public function resolve(Request $request): ?Revision
    {
        return RevisionValue::parse($this->config->enum, $request->route($this->parameter));
    }
}
