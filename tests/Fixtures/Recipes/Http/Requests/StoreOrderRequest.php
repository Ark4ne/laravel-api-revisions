<?php

declare(strict_types=1);

namespace Test\Fixtures\Recipes\Http\Requests;

use Ark4ne\ApiRevisions\Laravel\Requests\RevisionedRequest;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Foundation\Http\FormRequest;
use Test\Fixtures\Recipes\ApiRevision;
use Test\Fixtures\Recipes\Enums\OrderStatus;

final class StoreOrderRequest extends FormRequest implements Revisioned
{
    use RevisionedRequest;

    /**
     * @return array<string, Descriptor<mixed>>
     */
    protected function body(): array
    {
        return [
            'title' => Schema::string()->required()->max(255),
            'status' => Schema::enum(OrderStatus::class)
                ->was(ApiRevision::Winter25, name: 'state')
                ->was(
                    ApiRevision::Genesis,
                    name: 'paid',
                    schema: Schema::bool(),
                    up: fn(bool $paid): OrderStatus => $paid ? OrderStatus::Paid : OrderStatus::Pending,
                ),
            'total' => Schema::int()->required()->min(0)
                ->was(
                    ApiRevision::Winter25,
                    schema: Schema::string(),
                    up: fn(string $amount): int => (int) round(((float) $amount) * 100),
                )
                ->was(ApiRevision::Genesis, schema: Schema::float()),
            'tags' => Schema::list(Schema::string())->since(ApiRevision::Spring26, default: []),

            ...Schema::since(ApiRevision::Spring26, [
                'first_name' => Schema::string()->required(),
                'last_name' => Schema::string()->required(),
            ], default: null),
            ...Schema::until(ApiRevision::Winter25, [
                'name' => Schema::string()->required(),
            ]),
        ];
    }

    /**
     * @return array<string, Descriptor<mixed>>
     */
    protected function queryParameters(): array
    {
        return [
            'notify' => Schema::bool()->was(ApiRevision::Winter25, name: 'send_email'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    protected function upgrade(Revision $to, array $input): array
    {
        if ($to === ApiRevision::Spring26 && is_string($input['name'] ?? null)) {
            [$input['first_name'], $input['last_name']] = explode(' ', $input['name'], 2) + [1 => ''];
        }

        return $input;
    }
}
