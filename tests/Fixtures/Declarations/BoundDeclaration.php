<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;

/**
 * A response whose closure reads the instance (`$this->resource`), as a `JsonResource` does: built on its subject by the
 * bridge (model.md D50).
 */
final readonly class BoundDeclaration implements Revisioned
{
    public function __construct(public mixed $resource) {}

    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return (new self($subject))->toArray();
    }

    /**
     * @return array<string, Descriptor<mixed>>
     */
    public function toArray(): array
    {
        return [
            'label' => Schema::string(fn(): string => is_array($this->resource) && is_string($this->resource['label'] ?? null) ? strtoupper($this->resource['label']) : '?'),
        ];
    }
}
