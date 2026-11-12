<?php

declare(strict_types=1);

namespace Test\Fixtures\Resolvers;

use Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Illuminate\Http\Request;
use Test\Fixtures\ApiRevision;

/**
 * A custom resolver (US 8.2): the revision an account is pinned to, found by its bearer token, as Stripe pins an
 * account to the revision of its first call. Its option `accounts` maps a token to a revision value.
 */
final readonly class AccountPinnedResolver implements RevisionResolver
{
    /**
     * @param array<string, string> $accounts
     */
    public function __construct(private array $accounts = []) {}

    public function resolve(Request $request): ?Revision
    {
        $pinned = $this->accounts[(string) $request->bearerToken()] ?? null;

        return $pinned === null ? null : ApiRevision::from($pinned);
    }
}
