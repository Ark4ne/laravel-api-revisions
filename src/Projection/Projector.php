<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Projection;

use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Conversion\ExplicitConverter;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Schema\Change;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\InvalidValue;
use Ark4ne\ApiRevisions\Schema\Source;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use Ark4ne\ApiRevisions\Support\Invoke;

/**
 * Renders a subject in the shape of a revision (model.md §6.5, docs/architecture.md §8): the compiled `Shape` decides
 * which fields, under which names, through which conversions; the fresh descriptors of the instance give the sources,
 * conditions and closures. Per visible field, in the head order: presence conditions → source → normalize → `when()`
 * → down chain → serialize. Never mutates the subject.
 */
final readonly class Projector
{
    /**
     * @param Configuration $configuration the one the shapes were compiled with: it configures the descriptor that
     *                                     serializes (a date format, model.md D29)
     * @param Converters|null $converters the registry the chains are bound to; `TypeRegistry::converters()` by default
     */
    public function __construct(
        private Configuration $configuration = new Configuration(),
        private ?Converters $converters = null,
        private SourceReader $reader = new SourceReader(),
    ) {}

    /**
     * The fields of `$shape` read on `$subject`, `name at the revision → serialized value`, in the head order. A field
     * whose condition fails is omitted. A refused value or a failed conversion throws `ValueException`.
     *
     * @param array<string, Descriptor<mixed>> $descriptors the head descriptors of the instance, by head key
     *
     * @return array<string, mixed>
     */
    public function project(Shape $shape, array $descriptors, mixed $subject): array
    {
        $class = $shape->class ?? 'object';
        $converters = $this->converters ?? TypeRegistry::converters();
        $projected = [];
        foreach ($shape->fields as $key => $field) {
            $descriptor = $descriptors[$key] ?? throw DefinitionException::missingDescriptor($class, $key);
            if (!$this->present($descriptor, $key, $subject)) {
                continue;
            }
            $value = $this->read($descriptor->source(), $key, $subject);
            if ($value !== null) {
                $value = $descriptor->normalize($value);
                if ($value instanceof InvalidValue) {
                    throw ValueException::refused($class, $key, $shape->revision, $value->expected->value, get_debug_type($value->value));
                }
            }
            foreach ($descriptor->conditions() as $condition) {
                if (Invoke::value($condition, $value, $subject) !== true) {
                    continue 2;
                }
            }
            $projected[$field->name] = $value === null ? null : $this->render($field, $descriptor, $value, $subject, $converters, $class);
        }

        return $projected;
    }

    /**
     * Every presence condition holds: a `Presence` without path checks the source path, or the declared key when the
     * source has none (model.md D37).
     *
     * @param Descriptor<mixed> $descriptor
     */
    private function present(Descriptor $descriptor, string $key, mixed $subject): bool
    {
        foreach ($descriptor->presences() as $presence) {
            $path = $presence->path ?? $descriptor->source()->path;
            $path = $path === [] ? [$key] : $path;
            if (!$this->reader->has($subject, $path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The value of the source on the subject: a closure called with the peeked declared key and the subject, a key or
     * dotted path, the declared key, or the subject itself for an object without source.
     */
    private function read(Source $source, string $key, mixed $subject): mixed
    {
        return match (true) {
            $source->closure !== null => Invoke::value($source->closure, $this->reader->peek($subject, $key), $subject),
            $source->isParent() => $subject,
            default => $this->reader->read($subject, $source->path === [] ? [$key] : $source->path),
        };
    }

    /**
     * The normalized head value down to the shape of the revision, serialized by the descriptor in force there (the
     * head or the schema of a `was()`), configured.
     *
     * @param Descriptor<mixed> $descriptor
     */
    private function render(FieldDefinition $field, Descriptor $descriptor, mixed $value, mixed $subject, Converters $converters, string $class): mixed
    {
        $changes = $descriptor->history()->changes;
        if (!$field->chain->isEmpty()) {
            $explicit = array_map(static fn(Change $change): ?ExplicitConverter => $change->down, $changes);
            try {
                $value = $field->chain->bind($converters, $explicit)->apply($value, $subject);
            } catch (ConversionException) {
                throw ValueException::unconvertible($class, $field->headKey, $field->revision, get_debug_type($value), $field->type->kind->value);
            }
        }
        $serializer = $field->schemaChange === null
            ? $descriptor
            : ($changes[$field->schemaChange]->schema ?? throw DefinitionException::missingDescriptor($class, $field->headKey));

        return $serializer->configured($this->configuration)->serialize($value);
    }
}
