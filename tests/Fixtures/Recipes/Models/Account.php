<?php

declare(strict_types=1);

namespace Test\Fixtures\Recipes\Models;

use Illuminate\Foundation\Auth\User;
use Test\Fixtures\Recipes\ApiRevision;

/**
 * The API account of the recipes, never saved: the revision it is pinned to and where its webhooks go.
 *
 * @property ApiRevision|null $api_revision
 * @property string $webhook_url
 */
final class Account extends User
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['api_revision' => ApiRevision::class];
    }
}
