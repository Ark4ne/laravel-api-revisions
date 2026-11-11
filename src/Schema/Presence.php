<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;

/**
 * A presence condition (`->whenHas()`): checked on the subject **before** the source, so that a missing key is
 * omitted instead of read as `null` (model.md §6.4). A value, never a closure.
 */
final readonly class Presence
{
    /**
     * @param list<string>|null $path the key or dotted path checked; `null` for the field's own key, resolved by the
     *                                projection (epics.md Q15)
     */
    private function __construct(
        public ?array $path,
    ) {}

    /**
     * The subject has the key (dotted paths allowed), or the field's own key when `null`.
     */
    public static function has(?string $key = null): self
    {
        if ($key === null) {
            return new self(null);
        }
        $path = explode('.', $key);
        if (in_array('', $path, true)) {
            throw DefinitionException::invalidPresenceKey($key);
        }

        return new self($path);
    }
}
