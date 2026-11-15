<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Projection;

use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Conversion\ExplicitConverter;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\Change;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\ListSchema;
use Ark4ne\ApiRevisions\Schema\ObjectSchema;
use Ark4ne\ApiRevisions\Schema\ResourceSchema;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;

/**
 * Renders a subject in the shape of a revision (model.md §6.5, docs/architecture.md §8): the compiled `Shape` decides
 * which fields, under which names, through which conversions; the fresh descriptors of the instance give the sources,
 * conditions and closures. Per visible field, in the head order: presence conditions → source → normalize → `when()`
 * → down chain → serialize. Never mutates the subject. Projects responses: a nested resource is the `Response` shape of
 * its class (model.md D49).
 */
final readonly class Projector
{
    private Shapes $shapes;

    private ValueErrorHandler $values;

    /**
     * @param Configuration $configuration the one the shapes were compiled with: it configures the descriptor that
     *                                     serializes (a date format, model.md D29)
     * @param Converters|null $converters the registry the chains are bound to; `TypeRegistry::converters()` by default
     * @param Shapes|null $shapes the shapes of the nested resources (model.md D49); one compiling with `$configuration`
     *                            and `$converters` by default
     * @param ValueErrorHandler|null $values what a value error does; from `$configuration->strictValues` by default
     *                                       (`StrictValues`, or `LenientValues` without reporter)
     */
    public function __construct(
        private Configuration $configuration = new Configuration(),
        private ?Converters $converters = null,
        private SourceReader $reader = new PlainSourceReader(),
        ?Shapes $shapes = null,
        ?ValueErrorHandler $values = null,
    ) {
        $this->values = $values ?? ($configuration->strictValues ? new StrictValues() : new LenientValues());
        $this->shapes = $shapes ?? new Shapes(new Compiler($configuration, $converters));
    }

    /**
     * The fields of `$shape` read on `$subject`, `name at the revision → serialized value`, in the head order. A field
     * whose condition fails is omitted. A refused value, a failed conversion and a non-nullable `null` go to the
     * `ValueErrorHandler` (model.md D53).
     *
     * @param array<string, Descriptor<mixed>> $descriptors the head descriptors of the instance, by head key
     *
     * @return array<string, mixed>
     */
    public function project(Shape $shape, array $descriptors, mixed $subject): array
    {
        return $this->plan($shape, $descriptors)->run($subject);
    }

    /**
     * The projection of `$shape` with the head descriptors of one render, resolved ahead and run per subject
     * (model.md D86): `$keys` keeps those head keys of the top level, in the head order (a key not in the shape is
     * ignored); `null` keeps every field.
     *
     * @param array<string, Descriptor<mixed>> $descriptors the head descriptors of the render, by head key
     * @param list<string>|null $keys
     */
    public function plan(Shape $shape, array $descriptors, ?array $keys = null): ProjectionPlan
    {
        $class = $shape->class ?? 'object';
        $fields = $shape->fields;
        if ($keys !== null) {
            $fields = array_intersect_key($fields, array_flip($keys));
        }

        return new ProjectionPlan(
            $this->fields($fields, $descriptors, $class, $this->converters ?? TypeRegistry::converters()),
            $class,
            $this->reader,
            $this->values,
            $this->resource(...),
        );
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @param array<string, Descriptor<mixed>> $descriptors
     *
     * @return list<PlannedField>
     */
    private function fields(array $fields, array $descriptors, string $class, Converters $converters): array
    {
        $planned = [];
        foreach ($fields as $key => $field) {
            $planned[] = $this->field($field, $descriptors[$key] ?? throw DefinitionException::missingDescriptor($class, $key), $key, $class, $converters);
        }

        return $planned;
    }

    /**
     * One field (or item) resolved ahead: its presences (a `Presence` without path checks the source path, or the
     * declared key when the source has none, model.md D37; an item is the element itself, D51), its source, its bound
     * chain, its serializer (the head or the schema of a `was()`, configured) and its nested plan.
     *
     * @param Descriptor<mixed> $descriptor
     * @param string|null $key its head key; `null` for an item of a list
     */
    private function field(FieldDefinition $field, Descriptor $descriptor, ?string $key, string $class, Converters $converters): PlannedField
    {
        $source = $descriptor->source();
        $presences = [];
        foreach ($descriptor->presences() as $presence) {
            $path = $presence->path ?? $source->path;
            if ($path === []) {
                if ($key === null) {
                    continue;
                }
                $path = [$key];
            }
            $presences[] = [$presence->relation, $path];
        }
        // read: a key or dotted path, else the declared key; `null`: a closure, or the subject itself (an object without
        // source, an item)
        $path = null;
        if ($source->closure === null) {
            if ($source->path !== []) {
                $path = $source->path;
            } elseif (!$source->isParent() && $key !== null) {
                $path = [$key];
            }
        }

        $changes = $descriptor->history()->changes;
        $chain = $field->chain->isEmpty()
            ? null
            : $field->chain->bind($converters, array_map(static fn(Change $change): ?ExplicitConverter => $change->down, $changes));
        $serializer = $field->schemaChange === null
            ? $descriptor
            : ($changes[$field->schemaChange]->schema ?? throw DefinitionException::missingDescriptor($class, $field->headKey));

        $children = null;
        $items = null;
        $resource = null;
        if ($descriptor instanceof ObjectSchema && $field->children !== null) {
            $children = $this->fields($field->children->fields, $descriptor->children(), $class, $converters);
        } elseif ($descriptor instanceof ListSchema && $field->items !== null) {
            $items = $this->field($field->items, $descriptor->items(), null, $class, $converters);
        } elseif ($descriptor instanceof ObjectSchema || $descriptor instanceof ListSchema || $field->children !== null || $field->items !== null) {
            // descriptors that are not those of the shape: a composite on one side only
            throw DefinitionException::missingDescriptor($class, $field->headKey);
        } elseif ($descriptor instanceof ResourceSchema) {
            $resource = $descriptor->class();
        }

        return new PlannedField(
            $field,
            $key,
            $descriptor,
            // its own defaults only (`configure()`): serializing never reads the history `configured()` would configure
            $serializer->configure($this->configuration),
            $presences,
            $source->closure,
            $path,
            $descriptor->conditions(),
            $chain,
            $children,
            $items,
            $resource,
        );
    }

    /**
     * A nested resource: the shape of its class at the revision, declared on its value (model.md D49, D50, D86).
     *
     * @param class-string<Revisioned> $class
     *
     * @return array<string, mixed>
     */
    private function resource(string $class, Revision $revision, mixed $value): array
    {
        return $this->plan($this->shapes->for($class, DeclarationKind::Response, $revision), $class::describeRevisioned(DeclarationKind::Response, $value))->run($value);
    }
}
