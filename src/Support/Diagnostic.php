<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Support;

/**
 * One finding of the check: the code of its rule (kebab-case, the `CODE` constant of its check), its
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

    /**
     * The diagnostic as JSON-ready data (`revision:check --format=json`).
     *
     * @return array{code: string, severity: string, class: string, field: ?string, revision: ?string, message: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'class' => $this->class,
            'field' => $this->field,
            'revision' => $this->revision,
            'message' => $this->message,
        ];
    }

    public function isError(): bool
    {
        return $this->severity === Severity::Error;
    }
}
