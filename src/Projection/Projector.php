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
 * conditions and closures. Per visible field, in the head order: presence conditions → `when()` → source → normalize
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
     * (model.md D86): `$keys` keeps those head keys, in the head order; `null` keeps every field. A key may be a dotted
     * path of head keys (model.md D89): `address.city` keeps `address` with its child `city` only, `tags.*.name` the
     * child `name` of each item; a member left out is never evaluated; a shorter path wins; a path going on into a field
     * whose members are not planned one by one (converted at the revision, a list through a key, a resource, a scalar)
     * keeps it whole; a segment naming no child at the revision is ignored.
     *
     * @param array<string, Descriptor<mixed>> $descriptors the head descriptors of the render, by head key
     * @param list<string>|null $keys head keys or dotted paths of head keys
     */
    public function plan(Shape $shape, array $descriptors, ?array $keys = null): ProjectionPlan
    {
        $class = $shape->class ?? 'object';
        return new ProjectionPlan(
            $this->fields($shape->fields, $descriptors, $class, $this->converters ?? TypeRegistry::converters(), $keys === null ? null : self::tree($keys, $shape->fields)),
            $class,
            $this->reader,
            $this->values,
            $this->resource(...),
        );
    }

    /**
     * The paths kept, as a tree of head keys: `true` keeps the field whole (a shorter path wins).
     *
     * @param list<string> $keys
     * @param array<string, FieldDefinition> $fields the top level
     *
     * @return array<string, mixed>
     */
    private static function tree(array $keys, array $fields): array
    {
        $tree = [];
        foreach ($keys as $key) {
            // a head key of the top level holding a dot is kept whole, as before paths (model.md D89)
            $tree = self::insert($tree, isset($fields[$key]) ? [$key] : explode('.', $key));
        }

        return $tree;
    }

    /**
     * `$tree` keeping the path `$segments` too.
     *
     * @param array<string, mixed> $tree
     * @param list<string> $segments
     *
     * @return array<string, mixed>
     */
    private static function insert(array $tree, array $segments): array
    {
        $segment = array_shift($segments) ?? '';
        if (($tree[$segment] ?? null) === true) {
            return $tree;
        }
        $tree[$segment] = $segments === [] ? true : self::insert(self::branch($tree, $segment) ?? [], $segments);

        return $tree;
    }

    /**
     * The paths kept below `$key` in `$tree`: `null` when the member is kept whole (or not kept).
     *
     * @param array<string, mixed> $tree
     *
     * @return array<string, mixed>|null
     */
    private static function branch(array $tree, string $key): ?array
    {
        /** @var array<string, mixed>|true|null $branch a tree() node */
        $branch = $tree[$key] ?? null;

        return is_array($branch) ? $branch : null;
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @param array<string, Descriptor<mixed>> $descriptors
     * @param array<string, mixed>|null $tree the paths kept (`tree()`), `null` for every field
     *
     * @return list<PlannedField>
     */
    private function fields(array $fields, array $descriptors, string $class, Converters $converters, ?array $tree = null): array
    {
        $planned = [];
        foreach ($fields as $key => $field) {
            if ($tree !== null && !isset($tree[$key])) {
                continue;
            }
            $descriptor = $descriptors[$key] ?? throw DefinitionException::missingDescriptor($class, $key);
            $plannedField = $this->field($field, $descriptor, $key, $class, $converters, $tree === null ? null : self::branch($tree, $key));
            if ($plannedField !== null) {
                $planned[] = $plannedField;
            }
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
     * @param array<string, mixed>|null $tree the paths kept below it, `null` for every member; `null` returned when
     *                                        none of them names a member at the revision
     */
    private function field(FieldDefinition $field, Descriptor $descriptor, ?string $key, string $class, Converters $converters, ?array $tree = null): ?PlannedField
    {
        // members planned one by one only when nothing converts the field as a whole at the revision
        $members = $field->chain->isEmpty() && $field->schemaChange === null;
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
            $children = $this->fields($field->children->fields, $descriptor->children(), $class, $converters, $members ? $tree : null);
            if ($children === [] && $members && $tree !== null) {
                return null;
            }
        } elseif ($descriptor instanceof ListSchema && $field->items !== null) {
            // through `*` only: a path through a key keeps the list whole, in a union too
            $through = $members && $tree !== null && array_keys($tree) === ['*'] ? self::branch($tree, '*') : null;
            $items = $this->field($field->items, $descriptor->items(), null, $class, $converters, $through);
            if ($items === null) {
                return null;
            }
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
            $source->path !== [] ? implode('.', $source->path) : ($key ?? '*'),
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
