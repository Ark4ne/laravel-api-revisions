<?php

declare(strict_types=1);

namespace Test\Fixtures\Recipes\Http\Resolvers;

use Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Illuminate\Http\Request;
use Test\Fixtures\Recipes\Models\Account;

final readonly class AccountPinnedResolver implements RevisionResolver
{
    public function resolve(Request $request): ?Revision
    {
        $account = $request->user();

        return $account instanceof Account ? $account->api_revision : null;
    }
}
