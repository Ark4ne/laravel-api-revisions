<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use stdClass;

/**
 * Another `Revisioned` class (model.md D20): its value, the value of the source, is the subject of that class, which
 * projects it at the same revision with its own shape (model.md §6.1). Its type is a reference to the class
 * (`TypeDefinition::$resource`), never a copy of its shape: the nested shape is the class's shape of the same
 * declaration kind at the same revision (model.md D49), so cycles (`Order → Customer → Order`) compile.
 *
 * @extends Descriptor<array<mixed>|object> its value is the subject of the other class
 */
final class ResourceSchema extends Descriptor
{
    /**
     * @var class-string<Revisioned>
     */
    private readonly string $class;

    /**
     * @param class-string<Revisioned> $class anything else throws `DefinitionException::notRevisioned()`
     * @param mixed $source see `Descriptor::__construct()`
     */
    public function __construct(string $class, mixed $source = null)
    {
        parent::__construct($source);

        if (!is_subclass_of($class, Revisioned::class)) {
            throw DefinitionException::notRevisioned($class);
        }
        $this->class = $class;
    }

    /**
     * @return class-string<Revisioned>
     */
    public function class(): string
    {
        return $this->class;
    }

    public function normalize(mixed $value): mixed
    {
        return is_array($value) || is_object($value) ? $value : new InvalidValue($value, Kind::Object, null);
    }

    /**
     * The projection of the other class (`array<string, mixed>`) as it is, an empty one as `stdClass`.
     */
    public function serialize(mixed $value): mixed
    {
        return $value === [] ? new stdClass() : $value;
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::of(Kind::Object)->withResource($this->class);
    }
}
