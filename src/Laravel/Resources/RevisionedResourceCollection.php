<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;

/**
 * The collection of a `RevisionedResource` (`::collection()`, model.md D77, D86): one render runs `toArray()` once, on
 * a template instance, and projects every item through one plan; a declaration a plan cannot hold (D78, D86) resolves
 * each item, as `AnonymousResourceCollection` does. Pagination, wrapping, `additional()` and `preserveKeys()` are those of
 * `AnonymousResourceCollection`.
 */
final class RevisionedResourceCollection extends AnonymousResourceCollection
{
    /**
     * @return array<array-key, mixed>|\Illuminate\Contracts\Support\Arrayable<array-key, mixed>|JsonSerializable
     */
    public function toArray(Request $request)
    {
        $items = $this->collection?->all() ?? [];
        $resolve = [$this->collects, 'revisionedResolveAll'];
        if (self::resources($items, $this->collects) && is_callable($resolve)) {
            $rendered = $resolve($items, $request);
            if (is_array($rendered)) {
                return $rendered;
            }
        }

        return parent::toArray($request);
    }

    /**
     * Whether every item is a resource of the collected class: `collectResource()` wraps the items unless the first is
     * one already, so a collection may mix resources.
     *
     * @param array<array-key, mixed> $items
     */
    private static function resources(array $items, string $collects): bool
    {
        foreach ($items as $item) {
            if (!$item instanceof JsonResource || $item::class !== $collects) {
                return false;
            }
        }

        return true;
    }
}
