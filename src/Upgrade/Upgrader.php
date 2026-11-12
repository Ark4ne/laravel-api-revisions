<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Upgrade;

use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\FieldVersion;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Conversion\ExplicitConverter;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Exceptions\InvalidInputException;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Ark4ne\ApiRevisions\Schema\Change;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\InvalidValue;
use Ark4ne\ApiRevisions\Schema\ListSchema;
use Ark4ne\ApiRevisions\Schema\ObjectSchema;
use Ark4ne\ApiRevisions\Schema\ResourceSchema;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use Ark4ne\ApiRevisions\Support\Undefined;

/**
 * Upgrades a validated input of the revision of a request shape to the head (model.md §7.1, docs/architecture.md §9),
 * step by step `X → X->next()`: the renames and `up` conversions of the `was(X)`, read from a snapshot of the step (a
 * value normalized to the type of `X` first), then the hook, then the defaults of the fields added in `X->next()`, then
 * the fields retired after `X` are dropped. Absent keys stay absent, an explicit `null` is renamed but never converted
 * nor replaced by a default; keys the shape does not know are kept. The values converted or defaulted are serialized
 * back to raw values by the head descriptor (model.md D26). Pure: it never validates.
 */
final readonly class Upgrader
{
    /**
     * @param Configuration $configuration the one the shapes were compiled with: it configures the descriptor that
     *                                     serializes
     * @param Converters|null $converters the registry the chains are bound to; `TypeRegistry::converters()` by default
     * @param Shapes|null $shapes the shapes of the nested resources (model.md D49); one compiling with `$configuration`
     *                            and `$converters` by default
     */
    public function __construct(
        private Configuration $configuration = new Configuration(),
        private ?Converters $converters = null,
        ?Shapes $shapes = null,
    ) {
        $this->shapes = $shapes ?? new Shapes(new Compiler($configuration, $converters));
    }

    private Shapes $shapes;

    /**
     * The input of `$from->revision` in the shape of the head. A value its type at `X` refuses, or a conversion
     * rejects, throws an `InvalidInputException` on the client's key (model.md D63); a conversion missing its
     * `ConversionException`.
     *
     * @param Shape $from a request shape (direction up)
     * @param array<string, Descriptor<mixed>> $descriptors the head descriptors of the instance, by head key
     * @param array<string, mixed> $input validated at `$from->revision`
     *
     * @return array<string, mixed>
     */
    public function upgrade(Shape $from, array $descriptors, array $input, ?UpgradesInput $hook = null): array
    {
        $class = $from->class ?? 'object';
        if ($from->direction !== Direction::Up) {
            throw DefinitionException::notAnUpShape($class);
        }
        $run = new UpgradeRun($this->converters ?? TypeRegistry::converters(), $from->kind ?? DeclarationKind::RequestBody, $from->revision);
        /** @var array<array-key, mixed> $touched the head keys whose value was converted or defaulted: `true`, or the marks of its children or items */
        $touched = [];
        $revision = $from->revision;
        while (($next = RevisionOrder::next($revision)) !== null) {
            $input = $this->level($from, $descriptors, $input, $revision, $next, $hook, $touched, $class, $run);
            $revision = $next;
        }

        return $this->head($descriptors, $input, $touched, $run);
    }

    /**
     * One step `$revision → $next` on one level (the request, an object's children, a resource): the values of the
     * fields retired after `$revision` set aside; every field existing at both revisions read from a snapshot of the
     * level, renamed and converted (or its children and items upgraded), all old names removed before the new ones are
     * written (spike F1); the retired values put back under the names no newer field took; the hook (the request only);
     * the defaults of the fields added in `$next`; the retired values dropped.
     *
     * @param array<string, Descriptor<mixed>> $descriptors
     * @param array<array-key, mixed> $input
     * @param array<array-key, mixed> $touched
     *
     * @return array<array-key, mixed>
     */
    private function level(
        Shape $shape,
        array $descriptors,
        array $input,
        Revision $revision,
        Revision $next,
        ?UpgradesInput $hook,
        array &$touched,
        string $class,
        UpgradeRun $run,
        ?string $prefix = '',
    ): array {
        // the fields visible at the revision of the shape, and those added later, each compiled at its `since`
        $fields = $shape->fields + $shape->added;
        foreach (array_keys($fields) as $key) {
            if (!isset($descriptors[$key])) {
                throw DefinitionException::missingDescriptor($class, $key);
            }
        }
        $snapshot = $input;
        $aside = [];
        foreach ($fields as $field) {
            $name = $field->nameAt($revision);
            if ($field->lifetime->until === $revision && array_key_exists($name, $input)) {
                $aside[$name] = $input[$name];
                unset($input[$name]);
            }
        }
        $moves = [];
        foreach ($fields as $key => $field) {
            $old = $field->nameAt($revision);
            if (!$field->lifetime->covers($revision) || !$field->lifetime->covers($next) || !array_key_exists($old, $snapshot)) {
                continue;
            }
            [$value, $mark] = $this->value($field, $descriptors[$key], $snapshot[$old], $snapshot, $revision, $next, $touched[$key] ?? null, $class, $run, $prefix === null || !$field->lifetime->covers($run->client) ? null : $prefix . $field->nameAt($run->client));
            if ($mark === null) {
                unset($touched[$key]);
            } else {
                $touched[$key] = $mark;
            }
            $moves[] = [$old, $field->nameAt($next), $value];
        }
        foreach ($moves as [$old]) {
            unset($input[$old]);
        }
        foreach ($moves as [, $new, $value]) {
            $input[$new] = $value;
        }
        $retired = [];
        foreach ($aside as $name => $value) {
            if (!array_key_exists($name, $input)) {
                $input[$name] = $value;
                $retired[$name] = true;
            }
        }
        if ($hook !== null) {
            /** @var array<string, mixed> $named the request level is keyed by names */
            $named = $input;
            $input = $hook->upgrade($next, $named);
        }
        foreach ($shape->added as $key => $field) {
            $name = $field->name;
            // a retired value still under the name is no value of the field (model.md D54)
            if ($field->lifetime->since === $next && !Undefined::is($field->default)
                && (!array_key_exists($name, $input) || ($retired[$name] ?? false))) {
                $input[$name] = $field->default;
                $touched[$key] = true;
                unset($retired[$name]);
            }
        }
        foreach (array_keys($retired) as $name) {
            unset($input[$name]);
        }

        return $input;
    }

    /**
     * The value of one field (or item) after the step: converted when its chain has a step at the `was($revision)`
     * (a composite then as its `up` returns it, model.md D52), else the children, items or resource of a composite
     * upgraded where its head descriptor is in force; any other value as it is. With the mark of what was converted.
     *
     * @param Descriptor<mixed> $descriptor the head descriptor
     * @param array<array-key, mixed> $snapshot the snapshot of the level
     * @param mixed $mark what was converted in it before this step: `true`, the marks of its children or items, or `null`
     * @param string|null $path the client's key of the value: its names at the client's revision, dotted; `null` for a
     *                          value the client never sent (a default, a field added later): its rejection is a
     *                          declaration error (model.md D63)
     *
     * @return array{mixed, mixed} the value, and its mark
     */
    private function value(
        FieldDefinition $field,
        Descriptor $descriptor,
        mixed $value,
        array $snapshot,
        Revision $revision,
        Revision $next,
        mixed $mark,
        string $class,
        UpgradeRun $run,
        ?string $path,
    ): array {
        if ($value === null) {
            return [null, $mark];
        }
        $version = $field->versionAt($revision);
        if ($version !== null && $field->chain->stepOf($version->change) !== null) {
            // a composite converted is serialized whole: its children may be typed (model.md D55)
            return [$this->convert($field, $version, $descriptor, $value, $snapshot, $run->converters, $class, $path), true];
        }
        if (!is_array($value) || RevisionOrder::compare($field->headAt, $revision) > 0) {
            // before `headAt`, the value is in the shape of a `was()` schema: its own conversion takes it to the head's
            return [$value, $mark];
        }
        if (($descriptor instanceof ObjectSchema) !== ($field->children !== null)
            || ($descriptor instanceof ListSchema) !== ($field->items !== null)
            // the type is the head's when no `was()` schema is in force at the revision of the shape
            || ($field->headAt === $field->revision && ($descriptor instanceof ResourceSchema) !== ($field->type->resource !== null))) {
            // descriptors that are not those of the shape: a composite on one side only
            throw DefinitionException::missingDescriptor($class, $field->headKey);
        }
        $marks = is_array($mark) ? $mark : [];
        if ($field->children !== null && $descriptor instanceof ObjectSchema) {
            $value = $this->level($field->children, $descriptor->children(), $value, $revision, $next, null, $marks, $class, $run, $path === null ? null : "{$path}.");
        } elseif ($field->items !== null && $descriptor instanceof ListSchema) {
            // a list is marked whole: the hook may filter or reindex its items
            $converted = false;
            foreach ($value as $index => $element) {
                [$value[$index], $itemMark] = $this->value($field->items, $descriptor->items(), $element, $snapshot, $revision, $next, null, $class, $run, $path === null ? null : "{$path}.{$index}");
                $converted = $converted || $itemMark !== null;
            }

            return [$value, $converted ? true : $mark];
        } elseif ($descriptor instanceof ResourceSchema) {
            $resource = $descriptor->class();
            $value = $this->level(
                $this->shapes->for($resource, $run->kind, $field->headAt),
                $resource::describeRevisioned($run->kind, $value),
                $value,
                $revision,
                $next,
                null,
                $marks,
                $resource,
                $run,
                $path === null ? null : "{$path}.",
            );
        }

        if ($mark === true) {
            return [$value, true];
        }

        return [$value, $marks === [] ? null : $marks];
    }

    /**
     * The value normalized to the type in force at the revision of `$version`, then through the step crossing its
     * `was()`, which receives the snapshot of the step (model.md §7.1, D26). A value the type refuses, or the step
     * rejects, throws an `InvalidInputException` on `$path` (model.md D63); a step without converter, or a value the
     * client never sent (`$path` null), its own exception.
     *
     * @param Descriptor<mixed> $descriptor the head descriptor
     * @param array<array-key, mixed> $snapshot
     */
    private function convert(
        FieldDefinition $field,
        FieldVersion $version,
        Descriptor $descriptor,
        mixed $value,
        array $snapshot,
        Converters $converters,
        string $class,
        ?string $path,
    ): mixed {
        $changes = $descriptor->history()->changes;
        $current = $version->schemaChange === null
            ? $descriptor
            : ($changes[$version->schemaChange]->schema ?? throw DefinitionException::missingDescriptor($class, $field->headKey));
        $normalized = $current->normalize($value);
        if ($normalized instanceof InvalidValue) {
            $refused = ValueException::refused($class, $field->headKey, $version->revision, $normalized->expected->value, get_debug_type($normalized->value));

            throw $path === null ? $refused : InvalidInputException::at($path, $refused);
        }
        $explicit = array_map(static fn(Change $change): ?ExplicitConverter => $change->up, $changes);
        $step = $field->chain->bind($converters, $explicit)->stepOf($version->change);
        if ($step === null) {
            return $normalized;
        }
        try {
            return $step->apply($normalized, $snapshot);
        } catch (ConversionException $error) {
            throw $error->declarationError || $path === null ? $error : InvalidInputException::at($path, $error);
        }
    }

    /**
     * The upgraded input in the head order (keys the shape does not know last), the values converted or defaulted,
     * at any depth, serialized by their head descriptor.
     *
     * @param array<string, Descriptor<mixed>> $descriptors
     * @param array<array-key, mixed> $input
     * @param array<array-key, mixed> $touched
     *
     * @return array<string, mixed>
     */
    private function head(array $descriptors, array $input, array $touched, UpgradeRun $run): array
    {
        $input = $this->serialized($descriptors, $input, $touched, $run);
        $head = [];
        foreach (array_keys($descriptors) as $key) {
            if (array_key_exists($key, $input)) {
                $head[$key] = $input[$key];
            }
        }

        /** @var array<string, mixed> */
        return $head + $input;
    }

    /**
     * @param array<array-key, Descriptor<mixed>> $descriptors
     * @param array<array-key, mixed> $values
     * @param array<array-key, mixed> $marks
     *
     * @return array<array-key, mixed>
     */
    private function serialized(array $descriptors, array $values, array $marks, UpgradeRun $run): array
    {
        foreach ($marks as $key => $mark) {
            if (isset($descriptors[$key]) && array_key_exists($key, $values)) {
                $values[$key] = $this->serialize($descriptors[$key], $values[$key], $mark, $run);
            }
        }

        return $values;
    }

    /**
     * @param Descriptor<mixed> $descriptor the head descriptor
     */
    private function serialize(Descriptor $descriptor, mixed $value, mixed $mark, UpgradeRun $run): mixed
    {
        if ($value === null) {
            return null;
        }
        if (!$descriptor instanceof ObjectSchema && !$descriptor instanceof ListSchema && !$descriptor instanceof ResourceSchema) {
            return $mark === true ? $descriptor->configure($this->configuration)->serialize($value) : $value;
        }
        if (!is_array($value) || ($mark !== true && !is_array($mark))) {
            // a composite marked whole serializes each child or item, never itself (an empty one stays an array)
            return $value;
        }
        $children = match (true) {
            $descriptor instanceof ObjectSchema => $descriptor->children(),
            $descriptor instanceof ListSchema => array_fill_keys(array_keys($value), $descriptor->items()),
            $descriptor instanceof ResourceSchema => $descriptor->class()::describeRevisioned($run->kind, $value),
        };

        return $this->serialized($children, $value, $mark === true ? array_fill_keys(array_keys($value), true) : $mark, $run);
    }
}
