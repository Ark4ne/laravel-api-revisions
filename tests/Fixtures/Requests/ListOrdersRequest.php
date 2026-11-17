<?php

declare(strict_types=1);

namespace Test\Fixtures\Requests;

use Ark4ne\ApiRevisions\Laravel\Requests\RevisionedRequest;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Test\Fixtures\ApiRevision;

/**
 * A request with query parameters only, which keeps the default body and hook, and an `after` hook of its own that
 * fails on `limit=13`.
 */
final class ListOrdersRequest extends FormRequest implements Revisioned
{
    use RevisionedRequest;

    /**
     * @return array<string, Descriptor<mixed>>
     */
    protected function queryParameters(): array
    {
        return ['per_page' => Schema::int()->was(ApiRevision::Genesis, name: 'limit')];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            static function (Validator $validator): void {
                if (($validator->getData()['limit'] ?? null) === '13') {
                    $validator->errors()->add('limit', 'Unlucky.');
                }
            },
        ];
    }
}
