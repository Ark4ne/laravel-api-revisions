<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion;

/**
 * A converter the author chose (`down:` / `up:` of a `was()`): a `ClosureConverter` or a `Cast`. Never warned
 * `conversion-lossy`, whatever it loses: the loss is the author's call (model.md D25).
 */
interface ExplicitConverter extends Converter {}
