<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use stdClass;

/**
 * An object: named children, nested freely, each with its own source and history. Its value is the subject of its
 * children: the value of its source, or the parent's subject without one (`Source::parent()`).
 * `normalize()` only checks that subject (an array or an object); the projection then projects every child through
 * its own pipeline, and the object's `down:` and `serialize()` receive the projected children; its
 * `when()` runs before, on the subject. Serialized as a JSON object, even when empty.
 *
 * @extends Descriptor<array<mixed>|object> its value is the subject of its children
 */
final class ObjectSchema extends Descriptor
{
    /**
     * @var array<string, Descriptor<mixed>>
     */
    private array $children;

    /**
     * @var non-empty-string|null
     */
    private ?string $component = null;

    /**
     * @param array<string, Descriptor<mixed>> $children by key; anything else throws `DefinitionException::invalidChild()`
     * @param mixed $source see `Descriptor::__construct()`
     */
    public function __construct(array $children, mixed $source = null)
    {
        parent::__construct($source ?? Source::parent());

        foreach ($children as $key => $child) {
            // @phpstan-ignore function.alreadyNarrowedType (checked at run time: declarations are application code)
            if (!is_string($key) || $key === '') {
                throw DefinitionException::invalidChildKey($key);
            }
            // @phpstan-ignore instanceof.alwaysTrue (checked at run time: declarations are application code)
            if (!$child instanceof Descriptor) {
                throw DefinitionException::invalidChild($key, get_debug_type($child));
            }
        }
        $this->children = $children;
    }

    /**
     * Exports this object as the component `$name`, referenced from every use in a document;
     * `DefinitionException::invalidComponentName()` for an empty name.
     */
    public function component(string $name): static
    {
        if ($name === '') {
            throw DefinitionException::invalidComponentName();
        }
        $clone = clone $this;
        $clone->component = $name;

        return $clone;
    }

    /**
     * @return array<string, Descriptor<mixed>>
     */
    public function children(): array
    {
        return $this->children;
    }

    protected function parts(): array
    {
        return array_values($this->children);
    }

    public function configure(Configuration $configuration): static
    {
        $clone = clone $this;
        $clone->children = array_map(
            static fn(Descriptor $child): Descriptor => $child->configured($configuration),
            $this->children,
        );

        return $clone;
    }

    public function normalize(mixed $value): mixed
    {
        return is_array($value) || is_object($value) ? $value : new InvalidValue($value, Kind::Object, null);
    }

    /**
     * The serialized children (`array<string, mixed>`) as they are, an empty object as `stdClass`.
     */
    public function serialize(mixed $value): mixed
    {
        return $value === [] ? new stdClass() : $value;
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::of(Kind::Object)->withChildren(array_map(
            static fn(Descriptor $child): TypeDefinition => $child->toType()->withNullable($child->isNullable()),
            $this->children,
        ))->withComponent($this->component);
    }
}
