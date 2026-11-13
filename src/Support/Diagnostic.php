<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Support;

/**
 * One finding of the check (model.md §9): the code of its rule (kebab-case, the `CODE` constant of its check), its
 * severity, the class it was found in (a declaring class, or the revision enum), the head key of the field and the
 * backed value of the revision it concerns (`null` when it concerns the whole class, or every revision), and a message
 * for humans.
 */
final readonly class Diagnostic
{
    public function __construct(
        public string $code,
        public Severity $severity,
        public string $class,
        public ?string $field,
        public ?string $revision,
        public string $message,
    ) {}

    public function isError(): bool
    {
        return $this->severity === Severity::Error;
    }
}
