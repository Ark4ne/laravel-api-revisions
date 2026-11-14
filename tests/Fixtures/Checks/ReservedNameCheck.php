<?php

declare(strict_types=1);

namespace Test\Fixtures\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;

/**
 * A check contributed by a consumer (model.md D83): a top-level field may not take a reserved name, at any revision.
 */
final readonly class ReservedNameCheck implements Check
{
    public const string CODE = 'reserved-name';

    public function __construct(private ReservedNames $reserved) {}

    public function run(CheckContext $context): void
    {
        foreach ($context->kinds() as $kind) {
            foreach ($context->order() as $revision) {
                foreach ($context->shape($kind, $revision)->fields as $field) {
                    if (in_array($field->name, $this->reserved->names, true)) {
                        $context->error(self::CODE, "'{$field->name}' is reserved.", $field->headKey, $revision);
                    }
                }
            }
        }
    }
}
