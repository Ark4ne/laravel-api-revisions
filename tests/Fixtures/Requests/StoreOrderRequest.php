<?php

declare(strict_types=1);

namespace Test\Fixtures\Requests;

use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Laravel\Requests\RevisionedRequest;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Foundation\Http\FormRequest;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Enums\OrderStatus;

/**
 * The request of the reference scenario (model.md §7): a body whose fields change over the revisions, a hook splitting
 * `name` at `Atlas`, and query parameters renamed too (D63).
 */
final class StoreOrderRequest extends FormRequest implements Revisioned
{
    use RevisionedRequest;

    /**
     * @return array<string, Descriptor<mixed>>
     */
    protected function body(): array
    {
        return [
            'title' => Schema::string()->required()->rules('max:255'),
            'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
            'total' => Schema::int()->min(0)
                ->was(
                    ApiRevision::Winter25,
                    schema: Schema::string(),
                    up: static fn(string $amount): int => is_numeric($amount)
                        ? (int) round(((float) $amount) * 100)
                        : throw ConversionException::invalid('The total must be an amount.'),
                )
                ->was(ApiRevision::Genesis, schema: Schema::float()),
            'quantity' => Schema::int()->was(ApiRevision::Genesis, schema: Schema::float(), name: 'qty'),
            'address' => Schema::object([
                'street' => Schema::string()->required(),
                'zip' => Schema::string()->was(ApiRevision::Winter25, name: 'postcode'),
            ]),
            ...Schema::since(ApiRevision::Atlas, [
                'first_name' => Schema::string()->required(),
                'last_name' => Schema::string()->required(),
                'priority' => Schema::int()->required()->since(ApiRevision::Atlas, default: 0),
            ], default: null),
            ...Schema::until(ApiRevision::Spring26, [
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
            'filter' => Schema::object([
                'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
            ]),
            'per_page' => Schema::int()->min(1)->was(ApiRevision::Genesis, name: 'limit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['qty' => 'number of items'];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    protected function upgrade(Revision $to, array $input): array
    {
        if ($to === ApiRevision::Atlas && is_string($input['name'] ?? null)) {
            [$input['first_name'], $input['last_name']] = explode(' ', $input['name'], 2) + [1 => ''];
            unset($input['name']);
        }

        return $input;
    }
}
