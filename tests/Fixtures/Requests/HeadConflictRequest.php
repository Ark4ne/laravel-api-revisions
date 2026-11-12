<?php

declare(strict_types=1);

namespace Test\Fixtures\Requests;

use Ark4ne\ApiRevisions\Laravel\Requests\RevisionedRequest;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Foundation\Http\FormRequest;
use Test\Fixtures\ApiRevision;

/**
 * A request whose body and query parameters share the head key `status`, the query one named `state` up to
 * `Winter25`: their heads would collide for every client (model.md D63).
 */
final class HeadConflictRequest extends FormRequest implements Revisioned
{
    use RevisionedRequest;

    /**
     * @return array<string, Descriptor<mixed>>
     */
    protected function body(): array
    {
        return ['status' => Schema::string()];
    }

    /**
     * @return array<string, Descriptor<mixed>>
     */
    protected function queryParameters(): array
    {
        return ['status' => Schema::string()->was(ApiRevision::Winter25, name: 'state')];
    }
}
