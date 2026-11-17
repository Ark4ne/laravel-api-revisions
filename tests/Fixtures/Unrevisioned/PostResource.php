<?php

declare(strict_types=1);

namespace Test\Fixtures\Unrevisioned;

use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource of an application that configures no revision enum: its head only, no history.
 */
final class PostResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title' => Schema::string(),
            'views' => Schema::int()->min(0),
        ];
    }
}
