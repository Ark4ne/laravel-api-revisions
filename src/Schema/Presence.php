<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;

/**
 * A presence condition (`->whenHas()`, `->whenLoaded()`): checked on the subject **before** the source, so that a
 * missing key is omitted instead of read as `null`, and a relation not loaded is never loaded (model.md §6.4, D61). A
 * value, never a closure.
 */
final readonly class Presence
{
    /**
     * @param list<string>|null $path the key or dotted path checked; `null` for the field's own key, resolved by the
     *                                projection (model.md D37)
     * @param bool $relation whether `$path` names relations that must be loaded (`whenLoaded()`), not keys
     */
    private function __construct(
        public ?array $path,
        public bool $relation = false,
    ) {}

    /**
     * The subject has the key (dotted paths allowed), or the field's own key when `null`.
     */
    public static function has(?string $key = null): self
    {
        return $key === null ? new self(null) : new self(self::path($key));
    }

    /**
     * The relation `$relation` is loaded on the subject (dotted for a nested relation); on a subject without relations
     * (an array, a plain object), the subject has the key.
     */
    public static function loaded(string $relation): self
    {
        return new self(self::path($relation), true);
    }

    /**
     * @return non-empty-list<string>
     */
    private static function path(string $key): array
    {
        $path = explode('.', $key);
        if (in_array('', $path, true)) {
            throw DefinitionException::invalidPresenceKey($key);
        }

        return $path;
    }
}
