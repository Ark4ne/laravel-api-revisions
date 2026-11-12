<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Http;

use Ark4ne\ApiRevisions\Exceptions\UnknownRevisionException;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\ResolverChain;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The middleware resolving the revision of a request (model.md §8, alias `revision`): runs the `ResolverChain`, stores
 * the revision as the base of the request's `RevisionScope`, then adds to the response the configured
 * `response_header`, `Vary` on the headers the chain reads, and `Deprecation` (RFC 9745, `@` + Unix time) / `Sunset`
 * (RFC 8594, HTTP-date) from the revision's `#[Meta]` as soon as they are declared, at midnight of the day in the
 * application timezone (D30, D58). In path mode it forgets the route parameters of the chain's `PathResolver`s, so
 * no controller receives them, and makes the revision their default for the URLs generated (D60). An unknown revision is answered **400** with a JSON body naming the accepted
 * values, never reported (a client error). The `Vary` covers the headers of every resolver implementing `VariesOn`
 * (D59).
 */
final readonly class ResolveRevision
{
    public function __construct(
        private RevisionsConfig $config,
        private ResolverChain $chain,
        private RevisionScope $scope,
        private Repository $repository,
        private UrlGenerator $url,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $revision = $this->chain->resolve($request);
        } catch (UnknownRevisionException $unknown) {
            return $this->vary(new JsonResponse(
                ['message' => $unknown->getMessage(), 'revision' => $unknown->value, 'accepted' => $unknown->accepted],
                Response::HTTP_BAD_REQUEST,
                options: JSON_INVALID_UTF8_SUBSTITUTE,
            ));
        }
        $this->scope->setBase($revision);
        // path mode (D60): the controller never receives the revision, the URLs generated keep it
        foreach ($this->chain->parameters() as $parameter) {
            $request->route()?->forgetParameter($parameter);
            $this->url->defaults([$parameter => $revision->value]);
        }

        $response = $this->vary($next($request));
        $response->headers->set($this->config->responseHeader, (string) $revision->value);
        $meta = $revision->meta();
        if ($meta->deprecatedAt !== null) {
            $response->headers->set('Deprecation', '@' . $this->midnight($meta->deprecatedAt)->getTimestamp());
        }
        if ($meta->sunsetAt !== null) {
            $response->headers->set('Sunset', $this->midnight($meta->sunsetAt)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC7231));
        }

        return $response;
    }

    /**
     * `$response` varying on the headers the chain reads too.
     */
    private function vary(Response $response): Response
    {
        $headers = $this->chain->headers();

        return $headers === [] ? $response : $response->setVary($headers, false);
    }

    /**
     * Midnight of `$day` (`Y-m-d`) in the application timezone (D30).
     */
    private function midnight(string $day): DateTimeImmutable
    {
        $timezone = $this->repository->get('app.timezone');

        return new DateTimeImmutable("{$day} 00:00:00", new DateTimeZone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC'));
    }
}
