<?php

declare(strict_types=1);

namespace Test\Fixtures\Recipes\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Test\Fixtures\Recipes\ApiRevision;
use Test\Fixtures\Recipes\Http\Requests\StoreOrderRequest;
use Test\Fixtures\Recipes\Http\Resources\OrderResource;
use Test\Fixtures\Recipes\Models\Order;

final class OrderController
{
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $input = $request->validated();
        $order = new Order([
            'title' => $input['title'],
            'status' => $input['status'] ?? 'pending',
            'total_cents' => $input['total'],
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'tags' => $input['tags'] ?? [],
            'lines' => [],
        ]);

        return OrderResource::make($order)
            ->response()
            ->setStatusCode(ApiRevision::current()->isAtMost(ApiRevision::Winter25) ? 200 : 201);
    }
}
