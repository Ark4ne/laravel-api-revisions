<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Conversion\ConversionStep;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\ListSchema;
use Ark4ne\ApiRevisions\Schema\ObjectSchema;

/**
 * Walks a declaration for the checks: every descriptor or field, the children of an object and the items of a list
 * included, by its path of head keys (`customer.name`, `lines.*.sku`). A nested resource is not entered: its class is
 * checked on its own.
 *
 * @internal
 */
final class Fields
{
    /**
     * @param array<string, Descriptor<mixed>> $descriptors head descriptors by key
     *
     * @return iterable<string, Descriptor<mixed>> by path, parents before their children
     */
    public static function descriptors(array $descriptors, string $prefix = ''): iterable
    {
        foreach ($descriptors as $key => $descriptor) {
            yield from self::descriptor($descriptor, $prefix . $key);
        }
    }

    /**
     * The visible fields of `$shape`, the children and items of each at the shape's revision.
     *
     * @return iterable<string, FieldDefinition> by path, parents before their children
     */
    public static function of(Shape $shape, string $prefix = ''): iterable
    {
        foreach ($shape->fields as $key => $field) {
            yield from self::field($field, $prefix . $key);
        }
    }

    /**
     * Every shape of `$shape` by the path of its parent field: itself under `''`, then the children of its objects.
     *
     * @return iterable<string, Shape>
     */
    public static function shapes(Shape $shape, string $prefix = ''): iterable
    {
        yield $prefix => $shape;
        foreach (self::of($shape, $prefix === '' ? '' : $prefix . '.') as $path => $field) {
            if ($field->children !== null) {
                yield $path => $field->children;
            }
        }
    }

    /**
     * Every conversion step of the checked class, once per kind, field and `was()`, with the revision of its `was()`:
     * read from the visible fields of its shapes at every revision, oldest first.
     *
     * @return list<array{kind: DeclarationKind, path: string, step: ConversionStep, revision: Revision, direction: Direction}>
     */
    public static function steps(CheckContext $context): array
    {
        $steps = [];
        foreach ($context->kinds() as $kind) {
            foreach ($context->order() as $revision) {
                foreach (self::of($context->shape($kind, $revision)) as $path => $field) {
                    foreach ($field->chain->steps as $step) {
                        $steps["{$kind->value}@{$path}@{$step->change}"] ??= [
                            'kind' => $kind,
                            'path' => $path,
                            'step' => $step,
                            'revision' => self::revisionOf($field, $step->change),
                            'direction' => $field->chain->direction,
                        ];
                    }
                }
            }
        }

        return array_values($steps);
    }

    /**
     * The name of `$type` in a message: a custom type's name, an enum class, else its kind.
     */
    public static function typeName(TypeDefinition $type): string
    {
        return $type->name ?? $type->enum ?? $type->kind->value;
    }

    /**
     * The revision of the `was()` at index `$change` of `$field`.
     */
    private static function revisionOf(FieldDefinition $field, int $change): Revision
    {
        $revisions = [];
        foreach ($field->versions as $version) {
            $revisions[$version->change] = $version->revision;
        }

        // a step always crosses a `was()` of the field's versions down to its revision
        return $revisions[$change] ?? $field->revision;
    }

    /**
     * @param Descriptor<mixed> $descriptor
     *
     * @return iterable<string, Descriptor<mixed>>
     */
    private static function descriptor(Descriptor $descriptor, string $path): iterable
    {
        yield $path => $descriptor;
        if ($descriptor instanceof ObjectSchema) {
            yield from self::descriptors($descriptor->children(), $path . '.');
        } elseif ($descriptor instanceof ListSchema) {
            yield from self::descriptor($descriptor->items(), $path . '.*');
        }
    }

    /**
     * @return iterable<string, FieldDefinition>
     */
    private static function field(FieldDefinition $field, string $path): iterable
    {
        yield $path => $field;
        if ($field->children !== null) {
            yield from self::of($field->children, $path . '.');
        }
        if ($field->items !== null) {
            yield from self::field($field->items, $path . '.*');
        }
    }
}
