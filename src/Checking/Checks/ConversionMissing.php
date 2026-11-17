<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Checking\Fields;
use Ark4ne\ApiRevisions\Conversion\StepKind;

/**
 * An error per conversion step neither automatic nor declared, in the direction of the declaration kind, once per field and `was()`.
 */
final readonly class ConversionMissing implements Check
{
    public const string CODE = 'conversion-missing';

    public function run(CheckContext $context): void
    {
        foreach (Fields::steps($context) as ['kind' => $kind, 'path' => $path, 'step' => $step, 'revision' => $revision, 'direction' => $direction]) {
            if ($step->kind === StepKind::Missing) {
                $context->error(
                    self::CODE,
                    sprintf(
                        "No %s conversion from %s to %s for '%s' (%s) at was(%s): declare %s:.",
                        $direction->value,
                        Fields::typeName($step->from),
                        Fields::typeName($step->to),
                        $path,
                        $kind->value,
                        $revision->name,
                        $direction->value,
                    ),
                    $path,
                    $revision,
                );
            }
        }
    }
}
