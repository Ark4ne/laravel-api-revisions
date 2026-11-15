<?php

declare(strict_types=1);

namespace Test\Fixtures\Resources;

use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Test\Fixtures\Eloquent\Order;

/**
 * A resource counting its `toArray()` calls (model.md D86), whose closures read `$this->resource` and the request; it
 * opts out of the plan while `$perSubject` is set.
 *
 * @property Order $resource
 */
final class CountedResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    public static int $calls = 0;

    public static bool $perSubject = false;

    public static function revisionedPerSubject(): bool
    {
        return self::$perSubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        ++self::$calls;

        return [
            'title' => Schema::string(),
            'label' => Schema::string(fn(): string => strtoupper($this->resource->title)),
            'asked' => Schema::string(static fn(): string => (string) $request->query('asked', 'none')),
        ];
    }
}
