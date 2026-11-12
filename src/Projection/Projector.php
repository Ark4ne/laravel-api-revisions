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
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Schema\Change;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\InvalidValue;
use Ark4ne\ApiRevisions\Schema\ListSchema;
use Ark4ne\ApiRevisions\Schema\ObjectSchema;
use Ark4ne\ApiRevisions\Schema\ResourceSchema;
use Ark4ne\ApiRevisions\Schema\Source;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use Ark4ne\ApiRevisions\Support\Invoke;

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
        return $this->fields($shape, $descriptors, $subject, $shape->class ?? 'object', $this->converters ?? TypeRegistry::converters());
    }

    /**
     * @param array<string, Descriptor<mixed>> $descriptors
     *
     * @return array<string, mixed>
     */
    private function fields(Shape $shape, array $descriptors, mixed $subject, string $class, Converters $converters): array
    {
        $projected = [];
        foreach ($shape->fields as $key => $field) {
            $descriptor = $descriptors[$key] ?? throw DefinitionException::missingDescriptor($class, $key);
            [$present, $value] = $this->field($field, $descriptor, $key, $subject, $class, $converters);
            if ($present) {
                $projected[$field->name] = $value;
            }
        }

        return $projected;
    }

    /**
     * One field (or item) through the pipeline: whether it is rendered, and its serialized value. A composite projects
     * its children, items or resource between its normalization and its `when()` (model.md D34).
     *
     * @param Descriptor<mixed> $descriptor
     * @param string|null $key its head key; `null` for an item of a list, whose subject is the element itself
     *
     * @return array{bool, mixed}
     */
    private function field(FieldDefinition $field, Descriptor $descriptor, ?string $key, mixed $subject, string $class, Converters $converters): array
    {
        if (!$this->present($descriptor, $key, $subject)) {
            return [false, null];
        }
        $value = $this->read($descriptor->source(), $key, $subject);
        if ($value !== null) {
            $value = $descriptor->normalize($value);
            if ($value instanceof InvalidValue) {
                // lenient: its PHP cast rendered as it is, no closure ever sees a value outside its type (model.md D53)
                return [true, $this->values->handle(
                    ValueException::refused($class, $field->headKey, $field->revision, $value->expected->value, get_debug_type($value->value)),
                    $value->cast,
                )];
            }
            $value = $this->nested($field, $descriptor, $value, $class, $converters);
        }
        foreach ($descriptor->conditions() as $condition) {
            if (Invoke::value($condition, $value, $subject) !== true) {
                return [false, null];
            }
        }
        [$rendered, $reported] = $value === null ? [null, false] : $this->render($field, $descriptor, $value, $subject, $converters, $class);
        // a `null` resolved, or given by a `down:`, on a field that is not nullable at the revision
        if ($rendered === null && !$field->nullable && !$reported) {
            $this->values->handle(ValueException::nullNotAllowed($class, $field->headKey, $field->revision), null);
        }

        return [true, $rendered];
    }

    /**
     * The projected children of an object (on its value), items of a list (each element their subject) or resource (the
     * shape of its class at the same revision, its descriptors built on its value, model.md D49, D50); any other value
     * as it is.
     *
     * @param Descriptor<mixed> $descriptor the head descriptor (model.md D52)
     */
    private function nested(FieldDefinition $field, Descriptor $descriptor, mixed $value, string $class, Converters $converters): mixed
    {
        if ($descriptor instanceof ObjectSchema && $field->children !== null) {
            return $this->fields($field->children, $descriptor->children(), $value, $class, $converters);
        }
        if ($descriptor instanceof ListSchema && $field->items !== null) {
            $items = [];
            foreach ((array) $value as $element) {
                [$present, $item] = $this->field($field->items, $descriptor->items(), null, $element, $class, $converters);
                if ($present) {
                    $items[] = $item;
                }
            }

            return $items;
        }
        if ($descriptor instanceof ObjectSchema || $descriptor instanceof ListSchema || $field->children !== null || $field->items !== null) {
            // descriptors that are not those of the shape: a composite on one side only
            throw DefinitionException::missingDescriptor($class, $field->headKey);
        }
        if ($descriptor instanceof ResourceSchema) {
            $resource = $descriptor->class();

            return $this->fields(
                $this->shapes->for($resource, DeclarationKind::Response, $field->headAt),
                $resource::describeRevisioned(DeclarationKind::Response, $value),
                $value,
                $resource,
                $converters,
            );
        }

        return $value;
    }

    /**
     * Every presence condition holds: a `Presence` without path checks the source path, or the declared key when the
     * source has none (model.md D37); a relation presence asks the reader whether it is loaded (model.md D61).
     *
     * @param Descriptor<mixed> $descriptor
     */
    private function present(Descriptor $descriptor, ?string $key, mixed $subject): bool
    {
        foreach ($descriptor->presences() as $presence) {
            $path = $presence->path ?? $descriptor->source()->path;
            if ($path === []) {
                // an item is the element itself: present (model.md D51)
                if ($key === null) {
                    continue;
                }
                $path = [$key];
            }
            if (!($presence->relation ? $this->reader->loaded($subject, $path) : $this->reader->has($subject, $path))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The value of the source on the subject: a closure called with the peeked declared key (the element for an item)
     * and the subject, a key or dotted path, the declared key, or the subject itself for an object without source and
     * an item without source.
     */
    private function read(Source $source, ?string $key, mixed $subject): mixed
    {
        if ($source->closure !== null) {
            return Invoke::value($source->closure, $key === null ? $subject : $this->reader->peek($subject, $key), $subject);
        }
        if ($source->path !== []) {
            return $this->reader->read($subject, $source->path);
        }

        return $source->isParent() || $key === null ? $subject : $this->reader->read($subject, [$key]);
    }

    /**
     * The normalized head value down to the shape of the revision, serialized by the descriptor in force there (the
     * head or the schema of a `was()`), configured.
     *
     * @param Descriptor<mixed> $descriptor
     *
     * @return array{mixed, bool} the serialized value, and whether a value error was handled
     */
    private function render(FieldDefinition $field, Descriptor $descriptor, mixed $value, mixed $subject, Converters $converters, string $class): array
    {
        $changes = $descriptor->history()->changes;
        if (!$field->chain->isEmpty()) {
            $explicit = array_map(static fn(Change $change): ?ExplicitConverter => $change->down, $changes);
            try {
                $value = $field->chain->bind($converters, $explicit)->apply($value, $subject);
            } catch (ConversionException) {
                // lenient: no sensible cast between two types, `null` (model.md D53)
                return [$this->values->handle(
                    ValueException::unconvertible($class, $field->headKey, $field->revision, get_debug_type($value), $field->type->kind->value),
                    null,
                ), true];
            }
        }
        $serializer = $field->schemaChange === null
            ? $descriptor
            : ($changes[$field->schemaChange]->schema ?? throw DefinitionException::missingDescriptor($class, $field->headKey));

        // its own defaults only (`configure()`): serializing never reads the history `configured()` would configure too
        return [$serializer->configure($this->configuration)->serialize($value), false];
    }
}
