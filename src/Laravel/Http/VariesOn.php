<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Http;

/**
 * Optional contract of a `RevisionResolver` reading request headers: the `ResolveRevision`
 * middleware adds them to the `Vary` of the response, so a shared cache never serves the revision of one client to
 * another. Implemented by `HeaderResolver`; a custom resolver pinning an account by a header implements it too.
 */
interface VariesOn
{
    /**
     * The names of the request headers the resolver reads.
     *
     * @return list<string>
     */
    public function headers(): array;
}
