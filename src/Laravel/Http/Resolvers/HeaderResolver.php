<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Http\Resolvers;

use Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Illuminate\Http\Request;

/**
 * The revision named by the request header `$name` (`Api-Revision` by default): none when absent or empty,
 * `UnknownRevisionException` when it names no revision of the configured enum (`RevisionValue`).
 */
final readonly class HeaderResolver implements RevisionResolver
{
    public function __construct(
        private RevisionsConfig $config,
        private string $name = 'Api-Revision',
    ) {}

    public function resolve(Request $request): ?Revision
    {
        return RevisionValue::parse($this->config->enum, $request->headers->get($this->name));
    }
}
