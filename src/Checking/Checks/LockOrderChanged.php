<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Checking\Lock;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Exceptions\LockException;

/**
 * `lock-order-changed` (model.md §9, D69), on the enum: a locked revision whose previous revision in `order()` is not
 * the one recorded in its lock (moved, a revision inserted before it or removed), or which is no longer a case.
 */
final readonly class LockOrderChanged implements Check
{
    public const string CODE = 'lock-order-changed';

    public function __construct(private Lock $lock) {}

    public function run(CheckContext $context): void
    {
        if ($context->class !== null) {
            return;
        }
        $locked = $this->lock->locked();
        if ($locked === []) {
            return;
        }
        try {
            $order = $context->order();
        } catch (DefinitionException) {
            // an invalid order(): reported by order-missing-case / order-duplicate-case, or by the checker
            return;
        }
        foreach ($order as $revision) {
            $value = (string) $revision->value;
            if (!in_array($value, $locked, true)) {
                continue;
            }
            try {
                $recorded = $this->lock->read($value)->previous;
            } catch (LockException) {
                // reported by lock-mismatch
                continue;
            }
            $index = array_search($revision, $order, true);
            $current = $index === 0 || $index === false ? null : (string) $order[$index - 1]->value;
            if ($recorded !== $current) {
                $was = $recorded === null ? 'was the oldest' : "followed {$recorded}";
                $now = $current === null ? 'is now the oldest' : "now follows {$current}";
                $context->error(self::CODE, "The locked revision {$value} {$was} in order(), it {$now}: a published revision never moves.", revision: $revision);
            }
        }
        foreach ($locked as $value) {
            if ($context->revision($value) === null) {
                $context->error(self::CODE, "The locked revision {$value} is no longer a case of {$context->revisions}: a published revision never moves.");
            }
        }
    }
}
