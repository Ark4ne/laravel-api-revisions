<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Http\Resolvers;

use Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Illuminate\Http\Request;

/**
 * The revision named by the query parameter `$name` (`revision` by default): none when absent or empty,
 * `UnknownRevisionException` when it names no revision of the configured enum (an array included).
 */
final readonly class QueryResolver implements RevisionResolver
{
    public function __construct(
        private RevisionsConfig $config,
        private string $name = 'revision',
    ) {}

    public function resolve(Request $request): ?Revision
    {
        return RevisionValue::parse($this->config->enum, $request->query($this->name));
    }
}
