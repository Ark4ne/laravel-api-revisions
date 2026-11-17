<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;

/**
 * An error per case of the revision enum absent from `order()`.
 */
final readonly class OrderMissingCase implements Check
{
    public const string CODE = 'order-missing-case';

    public function run(CheckContext $context): void
    {
        if ($context->class !== null) {
            return;
        }
        $order = $context->revisions::order();
        foreach ($context->revisions::cases() as $case) {
            if (!in_array($case, $order, true)) {
                $context->error(self::CODE, "{$case->name} is missing from order(): list every case once, oldest first.", revision: $case);
            }
        }
    }
}
