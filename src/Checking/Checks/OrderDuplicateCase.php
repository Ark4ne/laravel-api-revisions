<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;

/**
 * An error per case of the revision enum listed more than once in `order()` (model.md §9).
 */
final readonly class OrderDuplicateCase implements Check
{
    public const string CODE = 'order-duplicate-case';

    public function run(CheckContext $context): void
    {
        if ($context->class !== null) {
            return;
        }
        $order = $context->revisions::order();
        foreach ($context->revisions::cases() as $case) {
            $times = count(array_filter($order, static fn(mixed $listed): bool => $listed === $case));
            if ($times > 1) {
                $context->error(self::CODE, "{$case->name} is listed {$times} times in order(): list every case once.", revision: $case);
            }
        }
    }
}
