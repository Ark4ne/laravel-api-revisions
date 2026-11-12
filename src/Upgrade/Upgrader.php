<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Upgrade;

use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\FieldVersion;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Conversion\ExplicitConverter;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Ark4ne\ApiRevisions\Schema\Change;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\InvalidValue;
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
     */
    public function __construct(
        private Configuration $configuration = new Configuration(),
        private ?Converters $converters = null,
    ) {}

    /**
     * The input of `$from->revision` in the shape of the head. A value its type at `X` refuses throws a
     * `ValueException`, a failed conversion its `ConversionException`.
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
        $converters = $this->converters ?? TypeRegistry::converters();
        // the fields visible at the revision, and those added later, each compiled at its `since`
        $fields = $from->fields + $from->added;
        foreach (array_keys($fields) as $key) {
            if (!isset($descriptors[$key])) {
                throw DefinitionException::missingDescriptor($class, $key);
            }
        }
        /** @var array<string, true> $touched the head keys whose value was converted or defaulted */
        $touched = [];
        $revision = $from->revision;
        while (($next = RevisionOrder::next($revision)) !== null) {
            [$input, $retired] = $this->step($fields, $descriptors, $input, $revision, $next, $converters, $class, $touched);
            if ($hook !== null) {
                $input = $hook->upgrade($next, $input);
            }
            foreach ($from->added as $key => $field) {
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
            $revision = $next;
        }

        return $this->head($descriptors, $input, $touched);
    }

    /**
     * The renames and `up` conversions of the `was($revision)` of the fields existing at both revisions, every old
     * name read from a snapshot of the step, all removed before the new ones are written (spike F1). The values of the
     * fields retired after `$revision` are set aside and put back under their name unless a newer field took it, for
     * the hook; their names are returned, to be dropped after the hook.
     *
     * @param array<string, FieldDefinition> $fields
     * @param array<string, Descriptor<mixed>> $descriptors
     * @param array<string, mixed> $input
     * @param array<string, true> $touched
     *
     * @return array{array<string, mixed>, array<string, true>} the input, and the names of the retired values put back
     */
    private function step(
        array $fields,
        array $descriptors,
        array $input,
        Revision $revision,
        Revision $next,
        Converters $converters,
        string $class,
        array &$touched,
    ): array {
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
            $version = $field->versionAt($revision);
            if ($version === null || !$field->lifetime->covers($next) || !array_key_exists($version->name, $snapshot)) {
                continue;
            }
            $value = $snapshot[$version->name];
            if ($value !== null && $field->chain->stepOf($version->change) !== null) {
                $value = $this->convert($field, $version, $descriptors[$key], $value, $snapshot, $converters, $class);
                $touched[$key] = true;
            }
            $moves[] = [$version->name, $field->nameAt($next), $value];
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

        return [$input, $retired];
    }

    /**
     * The value normalized to the type in force at the revision of `$version`, then through the step crossing its
     * `was()`, which receives the snapshot of the step (model.md §7.1, D26).
     *
     * @param Descriptor<mixed> $descriptor the head descriptor
     * @param array<string, mixed> $snapshot
     */
    private function convert(
        FieldDefinition $field,
        FieldVersion $version,
        Descriptor $descriptor,
        mixed $value,
        array $snapshot,
        Converters $converters,
        string $class,
    ): mixed {
        $changes = $descriptor->history()->changes;
        $current = $version->schemaChange === null
            ? $descriptor
            : ($changes[$version->schemaChange]->schema ?? throw DefinitionException::missingDescriptor($class, $field->headKey));
        $normalized = $current->normalize($value);
        if ($normalized instanceof InvalidValue) {
            throw ValueException::refused($class, $field->headKey, $version->revision, $normalized->expected->value, get_debug_type($normalized->value));
        }
        $explicit = array_map(static fn(Change $change): ?ExplicitConverter => $change->up, $changes);
        $step = $field->chain->bind($converters, $explicit)->stepOf($version->change);

        return $step === null ? $normalized : $step->apply($normalized, $snapshot);
    }

    /**
     * The upgraded input in the head order (keys the shape does not know last), the values converted or defaulted
     * serialized by their head descriptor.
     *
     * @param array<string, Descriptor<mixed>> $descriptors
     * @param array<string, mixed> $input
     * @param array<string, true> $touched
     *
     * @return array<string, mixed>
     */
    private function head(array $descriptors, array $input, array $touched): array
    {
        $head = [];
        foreach ($descriptors as $key => $descriptor) {
            if (array_key_exists($key, $input)) {
                $value = $input[$key];
                $head[$key] = ($touched[$key] ?? false) && $value !== null
                    ? $descriptor->configure($this->configuration)->serialize($value)
                    : $value;
            }
        }

        return $head + $input;
    }
}
