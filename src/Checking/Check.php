<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

/**
 * One rule of the check (Specification pattern): adds the diagnostics of its code to the context, never
 * throws on application code. Run once on the revision enum (`CheckContext::$class` is `null`), then once per
 * declaring class: a check returns at once on a target it does not check.
 */
interface Check
{
    public function run(CheckContext $context): void;
}
