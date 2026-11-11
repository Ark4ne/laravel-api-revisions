<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

/**
 * Implemented by a validation rule object that knows its JSON Schema: its keywords enrich the export of the field it
 * is declared on (model.md §3), whatever the descriptor.
 */
interface DescribesSchema
{
    /**
     * JSON Schema keywords (`['pattern' => '^\d{5}$']`), JSON-ready.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array;
}
