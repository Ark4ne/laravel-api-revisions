<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Compilation;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Configuration\MissingDefault;
use Ark4ne\ApiRevisions\Conversion\ConversionChain;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Ark4ne\ApiRevisions\Schema\Change;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\ListSchema;
use Ark4ne\ApiRevisions\Schema\ObjectSchema;
use Ark4ne\ApiRevisions\Support\Undefined;

/**
 * The one pure function of the model (model.md §4.5): the head descriptors of a class and a revision give the
 * `Shape` of that revision. Nothing else decides visibility, names, types at a revision or conversion plans. Runs no
 * source, no condition, no converter: it reads declarations only.
 */
final class ShapeSelector
{
    /**
     * The key of the items of a list, in their field definition.
     */
    public const ITEMS = '*';

    /**
     * The fields visible at `$revision` (their lifetime covers it), each with its name, type and chain at
     * `$revision`: the `was()` at or after `$revision` apply, newest first, each one a delta from the next newer shape.
     * Objects and lists recurse: children and items follow their own history. Up only, the fields added after
     * `$revision`, each at its `since`. Names written twice are recorded, never thrown.
     *
     * @param array<string, Descriptor<mixed>> $head the head descriptors, by key, groups already spread
     * @param Direction $direction the direction of the declaration kind (model.md D27): down for a response, up for a
     *                             request; the chains plan that direction only
     * @param Converters|null $converters the registry the chains are planned against (`TypeRegistry::converters()` for
     *                                    the custom types); the built-in one by default
     * @param Configuration|null $configuration its `missingDefault` gives `null` to a `required()->since()` field
     *                                          without default (model.md D47); the default configuration otherwise
     */
    public static function at(
        array $head,
        Revision $revision,
        Direction $direction,
        ?Converters $converters = null,
        ?Configuration $configuration = null,
    ): Shape {
        return self::shape(
            $head,
            $revision,
            $direction,
            $converters ?? Converters::builtIn(),
            ($configuration ?? new Configuration())->missingDefault,
        );
    }

    /**
     * @param array<string, Descriptor<mixed>> $head
     */
    private static function shape(array $head, Revision $revision, Direction $direction, Converters $converters, MissingDefault $missingDefault): Shape
    {
        $fields = [];
        $added = [];
        foreach ($head as $key => $descriptor) {
            $lifetime = $descriptor->lifetime();
            if ($lifetime->covers($revision)) {
                $field = self::field($key, $descriptor, $revision, $direction, $converters, $missingDefault);
                $fields[$key] = $field;
            } elseif ($direction === Direction::Up
                && $lifetime->since !== null
                && RevisionOrder::compare($lifetime->since, $revision) > 0) {
                $added[$key] = self::field($key, $descriptor, $lifetime->since, $direction, $converters, $missingDefault);
            }
        }
        $names = array_map(static fn(FieldDefinition $field): string => $field->name, $fields);
        $collisions = [];
        foreach (array_unique($names) as $name) {
            $keys = array_keys($names, $name, true);
            if (count($keys) > 1) {
                $collisions[] = new Collision($name, $keys);
            }
        }

        return new Shape($revision, $direction, $fields, $added, $collisions);
    }

    /**
     * @param Descriptor<mixed> $head
     */
    private static function field(
        string $key,
        Descriptor $head,
        Revision $revision,
        Direction $direction,
        Converters $converters,
        MissingDefault $missingDefault,
    ): FieldDefinition {
        $versions = [];
        $chain = ConversionChain::empty($direction);
        $steps = [];
        $newer = $head;
        $headAt = null;
        $schemaChange = null;
        foreach (self::walk($key, $head, $revision) as [$change, $index, $name, $descriptor]) {
            if ($change->schema !== null) {
                // the head is in force up to the revision after its newest `was(schema:)` (model.md D52)
                $headAt ??= self::next($change->revision);
                $schemaChange = $index;
            }
            $type = self::typeOf($descriptor, $change->revision);
            $versions[] = new FieldVersion($change->revision, $index, $name, $type, $schemaChange);
            $steps[] = [self::typeOf($newer, self::next($change->revision)), $type, $index, $change];
            $newer = $descriptor;
        }
        // A `was()` without `schema:` keeps the type of the newer shape: it converts only through an explicit converter
        // of the direction; its children or items differing at both revisions carry their own steps.
        if ($direction === Direction::Down) {
            foreach ($steps as [$from, $to, $index, $change]) {
                $explicit = $change->down !== null;
                if ($explicit || $change->schema !== null) {
                    $chain = $chain->withStep($from, $to, $index, $explicit, $converters);
                }
            }
        } else {
            foreach (array_reverse($steps) as [$to, $from, $index, $change]) {
                $explicit = $change->up !== null;
                if ($explicit || $change->schema !== null) {
                    $chain = $chain->withStep($from, $to, $index, $explicit, $converters);
                }
            }
        }
        [$name, $current, $schemaChange] = self::inForce($key, $head, $revision);
        $items = null;
        $children = null;
        // the items and children of the head where it is still in force (model.md D52): what the projection reads and
        // the conversions of a `was(schema:)` start from; the schema in force at `$revision` is the type (rules, export)
        $headAt ??= $revision;
        if ($head instanceof ListSchema) {
            $items = self::field(self::ITEMS, $head->items(), $headAt, $direction, $converters, $missingDefault);
        } elseif ($head instanceof ObjectSchema) {
            $children = self::shape($head->children(), $headAt, $direction, $converters, $missingDefault);
        }

        return new FieldDefinition(
            $key,
            $revision,
            $name,
            self::typeOf($current, $revision),
            $current->isNullable(),
            $head->isRequired(),
            $head->isSometimes(),
            $head->isConditional(),
            self::default($head, $missingDefault),
            $head->lifetime(),
            $schemaChange,
            $versions,
            $chain,
            $items,
            $children,
            $headAt,
        );
    }

    /**
     * The default of its `since()`; for a `required()->since()` field without one, `null` when the configuration says
     * so (model.md D47), an `Undefined` otherwise.
     *
     * @param Descriptor<mixed> $head
     */
    private static function default(Descriptor $head, MissingDefault $missingDefault): mixed
    {
        $default = $head->default();
        if (Undefined::is($default)
            && $missingDefault === MissingDefault::Null
            && $head->isRequired()
            && $head->lifetime()->since !== null) {
            return null;
        }

        return $default;
    }

    /**
     * The `was()` of `$head` at or after `$revision`, newest first, each with its index and the name and descriptor in
     * force up to its revision (the omitted arguments inherited from the next newer shape).
     *
     * @param Descriptor<mixed> $head
     *
     * @return list<array{Change, int, string, Descriptor<mixed>}>
     */
    private static function walk(string $key, Descriptor $head, Revision $revision): array
    {
        $walked = [];
        $name = $key;
        $descriptor = $head;
        foreach ($head->history()->changes as $index => $change) {
            if (RevisionOrder::compare($change->revision, $revision) < 0) {
                break;
            }
            $name = $change->name ?? $name;
            $descriptor = $change->schema ?? $descriptor;
            $walked[] = [$change, $index, $name, $descriptor];
        }

        return $walked;
    }

    /**
     * The name and the descriptor in force at `$revision`, with the index of the `was()` giving that descriptor
     * (`null` for the head).
     *
     * @param Descriptor<mixed> $head
     *
     * @return array{string, Descriptor<mixed>, int|null}
     */
    private static function inForce(string $key, Descriptor $head, Revision $revision): array
    {
        $name = $key;
        $descriptor = $head;
        $schemaChange = null;
        foreach (self::walk($key, $head, $revision) as [$change, $index, $walkedName, $walkedDescriptor]) {
            $name = $walkedName;
            $descriptor = $walkedDescriptor;
            if ($change->schema !== null) {
                $schemaChange = $index;
            }
        }

        return [$name, $descriptor, $schemaChange];
    }

    /**
     * The type of `$descriptor` itself (never its own `was()`) at `$revision`: the items and the visible children of a
     * composite are those in force at `$revision`, the children under their name there (the first child of a colliding
     * name), each nullable as the descriptor in force says (model.md D68).
     *
     * @param Descriptor<mixed> $descriptor
     */
    private static function typeOf(Descriptor $descriptor, Revision $revision): TypeDefinition
    {
        $type = $descriptor->toType();
        if ($descriptor instanceof ListSchema) {
            $items = self::inForce(self::ITEMS, $descriptor->items(), $revision)[1];

            return $type->withItems(self::typeOf($items, $revision)->withNullable($items->isNullable()));
        }
        if ($descriptor instanceof ObjectSchema) {
            $children = [];
            foreach ($descriptor->children() as $key => $child) {
                if ($child->lifetime()->covers($revision)) {
                    [$name, $current] = self::inForce($key, $child, $revision);
                    // two children under one name: the first one is kept, the nested shape records the collision
                    $children[$name] ??= self::typeOf($current, $revision)->withNullable($current->isNullable());
                }
            }

            return $type->withChildren($children);
        }

        return $type;
    }

    /**
     * The revision after a `was()` revision: never the head, which refuses a `was()` (`History::withChange()`).
     */
    private static function next(Revision $revision): Revision
    {
        return RevisionOrder::next($revision) ?? throw DefinitionException::invalidHistory($revision, 'the head has no older shape');
    }
}
