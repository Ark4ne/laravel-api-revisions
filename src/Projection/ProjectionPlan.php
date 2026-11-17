<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Projection;

use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\InvalidValue;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Support\Invoke;
use Closure;
use stdClass;

/**
 * The projection of one declaration at one revision, resolved ahead of its subjects: built by
 * `Projector::plan()` from a `Shape` and the descriptors of one render, then run on each subject. Per field, in the head
 * order: presence conditions → `when()` → source → normalize → nested plan → down chain → serialize.
 * Holds the closures of that declaration: built per render, never kept across renders. Public contract for consumers
 * rendering many subjects of one declaration.
 */
final readonly class ProjectionPlan
{
    /**
     * A sentinel no value can be: the field is not rendered.
     */
    private stdClass $omitted;

    /**
     * @param list<PlannedField> $fields
     * @param string $class the declaring class, named by the value errors
     * @param Closure(class-string<Revisioned>, Revision, mixed): array<string, mixed> $resource projects a nested resource
     *
     * @internal built by `Projector::plan()`
     */
    public function __construct(
        private array $fields,
        private string $class,
        private SourceReader $reader,
        private ValueErrorHandler $values,
        private Closure $resource,
    ) {
        $this->omitted = new stdClass();
    }

    /**
     * The fields of the plan read on `$subject`, `name at the revision → serialized value`, in the head order; what
     * `Projector::project()` gives for the same shape, descriptors and subject. A field whose condition fails is
     * omitted; a refused value, a failed conversion and a non-nullable `null` go to the `ValueErrorHandler`.
     *
     * @return array<string, mixed>
     */
    public function run(mixed $subject): array
    {
        return $this->fields($this->fields, $subject);
    }

    /**
     * @param list<PlannedField> $fields
     *
     * @return array<string, mixed>
     */
    private function fields(array $fields, mixed $subject): array
    {
        $projected = [];
        foreach ($fields as $field) {
            $value = $this->field($field, $subject);
            if ($value !== $this->omitted) {
                $projected[$field->definition->name] = $value;
            }
        }

        return $projected;
    }

    /**
     * One field (or item) through the pipeline: its serialized value, or the sentinel when it is not rendered. Its
     * conditions run before its source; a composite projects its children, items or resource after its
     * normalization.
     */
    private function field(PlannedField $field, mixed $subject): mixed
    {
        foreach ($field->presences as [$relation, $path]) {
            if (!($relation ? $this->reader->loaded($subject, $path) : $this->reader->has($subject, $path))) {
                return $this->omitted;
            }
        }
        foreach ($field->conditions as $condition) {
            // before the value: a field refused is never read, normalized, projected nor converted
            if (!Invoke::condition($condition, $subject, $field->attribute)) {
                return $this->omitted;
            }
        }
        $definition = $field->definition;
        $value = match (true) {
            $field->closure !== null => Invoke::value($field->closure, $field->key === null ? $subject : $this->reader->peek($subject, $field->key), $subject),
            $field->path !== null => $this->reader->read($subject, $field->path),
            default => $subject,
        };
        if ($value !== null) {
            $value = $field->descriptor->normalize($value);
            if ($value instanceof InvalidValue) {
                // lenient: its PHP cast rendered as it is, no closure ever sees a value outside its type
                return $this->values->handle(
                    ValueException::refused($this->class, $definition->headKey, $definition->revision, $value->expected->value, get_debug_type($value->value)),
                    $value->cast,
                );
            }
            $value = $this->nested($field, $value);
        }
        if ($value !== null) {
            if ($field->chain !== null) {
                try {
                    $value = $field->chain->apply($value, $subject);
                } catch (ConversionException) {
                    // lenient: no sensible cast between two types, `null`
                    return $this->values->handle(
                        ValueException::unconvertible($this->class, $definition->headKey, $definition->revision, get_debug_type($value), $definition->type->kind->value),
                        null,
                    );
                }
            }
            $value = $field->serializer->serialize($value);
        }
        // a `null` resolved, or given by a `down:`, on a field that is not nullable at the revision
        if ($value === null && !$definition->nullable) {
            $this->values->handle(ValueException::nullNotAllowed($this->class, $definition->headKey, $definition->revision), null);
        }

        return $value;
    }

    /**
     * The projected children of an object (on its value), items of a list (each element their subject) or resource (the
     * shape of its class at the same revision, declared on its value); any other value as it is.
     */
    private function nested(PlannedField $field, mixed $value): mixed
    {
        if ($field->children !== null) {
            return $this->fields($field->children, $value);
        }
        if ($field->items !== null) {
            $items = [];
            foreach ((array) $value as $element) {
                $item = $this->field($field->items, $element);
                if ($item !== $this->omitted) {
                    $items[] = $item;
                }
            }

            return $items;
        }
        if ($field->resource !== null) {
            return ($this->resource)($field->resource, $field->definition->headAt, $value);
        }

        return $value;
    }
}
