<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Support;

/**
 * How serious a diagnostic is (model.md §9): an error fails the check, a warning is only reported.
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
