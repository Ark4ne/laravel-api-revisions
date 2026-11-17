<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Checking\Fields;
use Ark4ne\ApiRevisions\Conversion\StepKind;

/**
 * A warning per automatic conversion step whose converter may lose information (`Converter::lossless()` false), declared
 * without `down:` / `up:`, once per field and `was()`.
 */
final readonly class ConversionLossy implements Check
{
    public const string CODE = 'conversion-lossy';

    public function run(CheckContext $context): void
    {
        $converters = $context->converters();
        foreach (Fields::steps($context) as ['kind' => $kind, 'path' => $path, 'step' => $step, 'revision' => $revision, 'direction' => $direction]) {
            if ($step->kind === StepKind::Registered && $converters->find($step->from, $step->to, $direction)?->lossless() === false) {
                $context->warning(
                    self::CODE,
                    sprintf(
                        "The automatic %s conversion from %s to %s of '%s' (%s) at was(%s) may lose information: declare %s: to decide.",
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
