<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\EagerSchema;

/**
 * An error per plain value declared in a response instead of a descriptor (`EagerSchema`): typed `any`, the same in
 * every revision.
 */
final readonly class EagerValue implements Check
{
    public const string CODE = 'eager-value';

    public function run(CheckContext $context): void
    {
        if (!in_array(DeclarationKind::Response, $context->kinds(), true)) {
            return;
        }
        foreach ($context->descriptors(DeclarationKind::Response) as $key => $descriptor) {
            if ($descriptor instanceof EagerSchema) {
                $context->error(self::CODE, "'{$key}' (response) is a plain value, typed any and the same in every revision: declare a descriptor.", $key);
            }
        }
    }
}
