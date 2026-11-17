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
 * A request declaring `status` in its body and, up to `Winter25`, in its query parameters; it keeps the
 * default hook.
 */
final class ConflictingRequest extends FormRequest implements Revisioned
{
    use RevisionedRequest;

    /**
     * @return array<string, Descriptor<mixed>>
     */
    protected function body(): array
    {
        return ['status' => Schema::string(), 'note' => Schema::string()];
    }

    /**
     * @return array<string, Descriptor<mixed>>
     */
    protected function queryParameters(): array
    {
        return ['state' => Schema::string()->was(ApiRevision::Winter25, name: 'status')];
    }
}
