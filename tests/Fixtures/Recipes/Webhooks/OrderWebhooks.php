<?php

declare(strict_types=1);

namespace Test\Fixtures\Recipes\Webhooks;

use Illuminate\Support\Facades\Http;
use Test\Fixtures\Recipes\ApiRevision;
use Test\Fixtures\Recipes\Http\Resources\OrderResource;
use Test\Fixtures\Recipes\Jobs\SendOrderWebhook;
use Test\Fixtures\Recipes\Models\Account;
use Test\Fixtures\Recipes\Models\Order;

/**
 * Webhooks of the recipes: sent now or queued, always in the revision the account is pinned to.
 */
final class OrderWebhooks
{
    public function sendNow(Account $account, Order $order): void
    {
        ApiRevision::using($account->api_revision ?? ApiRevision::latest(), function () use ($account, $order): void {
            Http::post($account->webhook_url, ['type' => 'order.updated', 'data' => OrderResource::make($order)->resolve()]);
        });
    }

    public function queue(Account $account, Order $order): void
    {
        ApiRevision::using($account->api_revision ?? ApiRevision::latest(), function () use ($account, $order): void {
            SendOrderWebhook::dispatch($account->webhook_url, $order);
        });
    }
}
