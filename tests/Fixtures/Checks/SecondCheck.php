<?php

declare(strict_types=1);

namespace Test\Fixtures\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;

/**
 * A second contributed check, to assert the tag order: a warning on every class.
 */
final readonly class SecondCheck implements Check
{
    public function run(CheckContext $context): void
    {
        if ($context->class !== null) {
            $context->warning('second', 'Checked second.');
        }
    }
}
