<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Http;

use Ark4ne\ApiRevisions\Revisions\Revision;
use Illuminate\Http\Request;

/**
 * Reads the revision of a request (model.md §8): one link of the chain configured in `revisions.resolvers`, or the
 * `revisions.default` when it is a class. Built by the container, its options given as named constructor arguments;
 * the first resolver returning a revision wins. A custom resolver pins the revision of an account or a token
 * (Stripe-style).
 */
interface RevisionResolver
{
    /**
     * The revision the request asks for, `null` when it asks for none here; `UnknownRevisionException` when it asks
     * for a revision the API does not know.
     */
    public function resolve(Request $request): ?Revision;
}
