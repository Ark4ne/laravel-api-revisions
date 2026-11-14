<?php

declare(strict_types=1);

namespace Test\Fixtures\Recipes\Jobs;

use Ark4ne\ApiRevisions\Laravel\Queue\CapturesRevision;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Test\Fixtures\Recipes\Http\Resources\OrderResource;
use Test\Fixtures\Recipes\Models\Order;

final class SendOrderWebhook implements ShouldQueue, CapturesRevision
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly string $url, public readonly Order $order) {}

    public function handle(): void
    {
        Http::post($this->url, ['type' => 'order.updated', 'data' => OrderResource::make($this->order)->resolve()]);
    }
}
