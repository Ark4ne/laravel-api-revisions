<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Checking\Fields;

/**
 * A warning per extra `since()` or `until()` call on a descriptor, naming the revision it replaced (model.md §4.4 rule 5,
 * D38).
 */
final readonly class BoundRepeated implements Check
{
    public const string CODE = 'bound-repeated';

    public function run(CheckContext $context): void
    {
        foreach ($context->kinds() as $kind) {
            foreach (Fields::descriptors($context->descriptors($kind)) as $path => $descriptor) {
                foreach ($descriptor->repeatedBounds() as ['bound' => $bound, 'replaced' => $replaced]) {
                    $context->warning(
                        self::CODE,
                        "{$bound}() is called more than once on '{$path}' ({$kind->value}): the last call replaces {$replaced->name}.",
                        $path,
                        $replaced,
                    );
                }
            }
        }
    }
}
