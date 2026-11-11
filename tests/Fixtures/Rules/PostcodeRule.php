<?php

declare(strict_types=1);

namespace Test\Fixtures\Rules;

use Ark4ne\ApiRevisions\Schema\DescribesSchema;

/**
 * A rule object that knows its JSON Schema.
 */
final class PostcodeRule implements DescribesSchema
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        private readonly array $schema = ['pattern' => '^\d{5}$', 'examples' => ['75001']],
    ) {}

    public function toSchema(): array
    {
        return $this->schema;
    }
}
