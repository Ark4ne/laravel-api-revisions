<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion;

/**
 * How a step of a `ConversionChain` converts (spike finding F6: an enum, never a string).
 */
enum StepKind: string
{
    /** An automatic converter of the `Converters` registry, found again by its types when bound. */
    case Registered = 'registered';

    /** The `down:` / `up:` of a `was()`, found again by its index in the history when bound. */
    case Explicit = 'explicit';

    /** No converter: recorded (check code `conversion-missing`), thrown only when applied. */
    case Missing = 'missing';
}
