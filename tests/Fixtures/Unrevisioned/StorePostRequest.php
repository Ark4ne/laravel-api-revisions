<?php

declare(strict_types=1);

namespace Test\Fixtures\Unrevisioned;

use Ark4ne\ApiRevisions\Laravel\Requests\RevisionedRequest;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request of an application that configures no revision enum.
 */
final class StorePostRequest extends FormRequest implements Revisioned
{
    use RevisionedRequest;

    /**
     * @return array<string, Descriptor<mixed>>
     */
    protected function body(): array
    {
        return [
            'title' => Schema::string()->required()->max(80),
            'views' => Schema::int()->min(0),
        ];
    }
}
