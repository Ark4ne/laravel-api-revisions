<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Upgrade;

use Ark4ne\ApiRevisions\Revisions\Revision;

/**
 * The hook of a request: reshapes what the `was()` cannot express, once per step `X → $to`, after the
 * renames and conversions of the step and before the defaults of the fields added in `$to`.
 */
interface UpgradesInput
{
    /**
     * @param Revision $to the newer revision of the step
     * @param array<string, mixed> $input the input after the renames and conversions of the step, under the names of `$to`
     *
     * @return array<string, mixed> the input in the shape of `$to`
     */
    public function upgrade(Revision $to, array $input): array;
}
