<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Export;

use Ark4ne\ApiRevisions\Compilation\Shape;

/**
 * Turns the compiled shape of a class into a format (docs/architecture.md §11, Visitor): JSON Schema is the reference
 * one (`JsonSchemaExporter`); another format is another visitor, without touching the core.
 *
 * @template TResult
 */
interface SchemaVisitor
{
    /**
     * The document of `$shape`, the shape of a declaring class (`Shape::$class` and `$kind` set by the compiler).
     *
     * @return TResult
     */
    public function visit(Shape $shape): mixed;
}
