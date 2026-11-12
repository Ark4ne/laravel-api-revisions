<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Http\Resolvers;

use Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver;
use Ark4ne\ApiRevisions\Laravel\Http\VariesOn;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Illuminate\Http\Request;

/**
 * The resolvers of `revisions.resolvers`, in order (Chain of Responsibility, model.md §8): the first returning a
 * revision wins; when none does, the `default`: a fixed revision, or a resolver whose `null` gives the head (model.md
 * D57). An unknown value stops the chain (`UnknownRevisionException`). Not a `RevisionResolver` itself, so the
 * configuration never accepts it as a link or as the default (it would build itself).
 */
final readonly class ResolverChain
{
    /**
     * @param class-string<Revision> $enum
     * @param list<RevisionResolver> $resolvers
     */
    public function __construct(
        private string $enum,
        private array $resolvers,
        private Revision|RevisionResolver $default,
    ) {}

    /**
     * The revision of the request: the first resolver's, else the default's, else the head.
     */
    public function resolve(Request $request): Revision
    {
        foreach ($this->resolvers as $resolver) {
            $revision = $resolver->resolve($request);
            if ($revision !== null) {
                return $revision;
            }
        }
        if ($this->default instanceof Revision) {
            return $this->default;
        }

        return $this->default->resolve($request) ?? RevisionOrder::latest($this->enum);
    }

    /**
     * The request headers the chain reads, each once: those of its resolvers implementing `VariesOn`, the default
     * included (`Vary`, model.md D59).
     *
     * @return list<string>
     */
    public function headers(): array
    {
        $headers = [];
        foreach ([...$this->resolvers, $this->default] as $resolver) {
            if ($resolver instanceof VariesOn) {
                foreach ($resolver->headers() as $header) {
                    $headers[$header] = true;
                }
            }
        }

        return array_keys($headers);
    }
}
