<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Requests;

use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Upgrade\UpgradesInput;
use Closure;

/**
 * The `protected upgrade()` hook of a `RevisionedRequest` as the `UpgradesInput` of the upgrader (model.md §7.1).
 *
 * @internal
 */
final readonly class RequestHook implements UpgradesInput
{
    /**
     * @param Closure(Revision, array<string, mixed>): array<string, mixed> $hook
     */
    public function __construct(private Closure $hook) {}

    public function upgrade(Revision $to, array $input): array
    {
        return ($this->hook)($to, $input);
    }
}
