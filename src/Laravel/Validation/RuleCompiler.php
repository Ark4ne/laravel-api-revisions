<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Validation;

use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\Probe;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Compilation\ShapeSelector;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Conversion\StepKind;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\ArrayRules;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\DateRules;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\EnumRules;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\FixedRules;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\NumberRules;
use Ark4ne\ApiRevisions\Laravel\Validation\Mappers\StringRules;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\ListSchema;
use Ark4ne\ApiRevisions\Schema\ObjectSchema;
use Ark4ne\ApiRevisions\Schema\ResourceSchema;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use Illuminate\Validation\Rules\In;

/**
 * The Laravel validation rules of a request shape (**Visitor** over the types of the
 * shape): one entry per field visible at the revision, under its **name at that revision** (dotted for the children
 * of an object or a nested resource, `.*` for the items of a list), in this order: `sometimes` / `required` /
 * `nullable` as declared, the rules of its type at the revision (`RuleMapper` of its `Kind`), those its first up
 * conversion needs (`Converter::extraRules()`, the values of an enum it converts to), then the rules the author
 * declared (`rules()`, kept as given). The fields added after the revision are never validated.
 */
final readonly class RuleCompiler
{
    /**
     * @var array<string, RuleMapper>
     */
    private array $mappers;

    /**
     * @param Shapes $shapes the shapes of the nested resources
     * @param Converters|null $converters the registry the up chains are bound to; `TypeRegistry::converters()` by
     *                                    default
     * @param array<string, RuleMapper> $mappers by `Kind` value, replacing the default mapper of that kind; another
     *                                           key throws `DefinitionException::unknownKind()`
     */
    public function __construct(
        private Shapes $shapes,
        private ?Converters $converters = null,
        array $mappers = [],
    ) {
        foreach (array_keys($mappers) as $kind) {
            if (Kind::tryFrom($kind) === null) {
                throw DefinitionException::unknownKind($kind);
            }
        }
        $this->mappers = $mappers;
    }

    /**
     * The rules of `$shape`, by name at its revision. `DefinitionException::rulesOfAResponse()` for a response shape,
     * `missingDescriptor()` for descriptors that are not those of the shape.
     *
     * @param Shape $shape a request shape (direction up)
     * @param array<string, Descriptor<mixed>> $descriptors the head descriptors of the instance, by head key
     *
     * @return array<string, list<mixed>>
     */
    public function compile(Shape $shape, array $descriptors): array
    {
        $class = $shape->class ?? 'object';
        if ($shape->direction !== Direction::Up) {
            throw DefinitionException::rulesOfAResponse($class);
        }
        $rules = [];
        $this->level($shape, $descriptors, '', null, $rules, $shape->kind ?? DeclarationKind::RequestBody, $class, $this->converters ?? TypeRegistry::converters());

        return $rules;
    }

    /**
     * The rules of the visible fields of one level (the request, an object's children, a nested resource).
     *
     * @param string|null $guard the path of the nearest parent the client may leave out or send `null`: a `required`
     *                           below it becomes `required_unless:` it `,null`
     * @param array<string, Descriptor<mixed>> $descriptors
     * @param array<string, list<mixed>> $rules
     */
    private function level(Shape $shape, array $descriptors, string $prefix, ?string $guard, array &$rules, DeclarationKind $kind, string $class, Converters $converters): void
    {
        foreach ($shape->fields as $key => $field) {
            $descriptor = $descriptors[$key] ?? throw DefinitionException::missingDescriptor($class, $key);
            $this->field($field, $descriptor, $prefix . $field->name, $guard, $rules, $kind, $class, $converters);
        }
    }

    /**
     * One field (or the items of a list) of the shape: its presence from the shape, the rules of its type and of its
     * first up conversion, those declared on the descriptor in force at the revision; then its children, items or
     * resource, from the shape where the head is in force, from the type and the schema of a `was()` otherwise.
     *
     * @param Descriptor<mixed> $head the head descriptor
     * @param array<string, list<mixed>> $rules
     */
    private function field(FieldDefinition $field, Descriptor $head, string $path, ?string $guard, array &$rules, DeclarationKind $kind, string $class, Converters $converters): void
    {
        $current = $field->schemaChange === null
            ? $head
            : ($head->history()->changes[$field->schemaChange]->schema ?? throw DefinitionException::missingDescriptor($class, $field->headKey));
        self::add($rules, $path, [
            ...self::presence($field->sometimes, $field->required, $field->nullable, $guard),
            ...$this->typeRules($field->type),
            ...self::conversionRules($field, $converters),
            ...$current->declaredRules(),
        ]);
        $inner = self::guard($path, $guard, $field->headKey === ShapeSelector::ITEMS, $field->sometimes, $field->required, $field->nullable);
        if ($field->schemaChange !== null) {
            $this->nested($field->type, $current, $path, $inner, $rules, $kind, $field->revision, $converters);
        } elseif ($field->children !== null && $head instanceof ObjectSchema) {
            $this->level($field->children, $head->children(), "{$path}.", $inner, $rules, $kind, $class, $converters);
        } elseif ($field->items !== null && $head instanceof ListSchema) {
            // a wildcard expands to the items sent: an absent list has none
            $this->field($field->items, $head->items(), "{$path}.*", null, $rules, $kind, $class, $converters);
        } elseif ($field->children !== null || $field->items !== null || $head instanceof ObjectSchema || $head instanceof ListSchema) {
            // descriptors that are not those of the shape: a composite on one side only
            throw DefinitionException::missingDescriptor($class, $field->headKey);
        } elseif ($head instanceof ResourceSchema) {
            $this->resource($head->class(), $path, $inner, $rules, $kind, $field->revision, $converters);
        }
    }

    /**
     * The children, items or resource of the schema of a `was()` in force at the revision: a schema has no history nor
     * lifetime of its own, so its parts are named by their key and typed by `$type`, the type of the
     * field at the revision (never the head's `children`).
     *
     * @param Descriptor<mixed> $descriptor
     * @param array<string, list<mixed>> $rules
     */
    private function nested(TypeDefinition $type, Descriptor $descriptor, string $path, ?string $guard, array &$rules, DeclarationKind $kind, Revision $revision, Converters $converters): void
    {
        $parts = match (true) {
            $descriptor instanceof ObjectSchema => array_map(
                static fn(Descriptor $child, string $key): array => [".{$key}", $child, $type->children[$key] ?? null, $guard],
                $descriptor->children(),
                array_keys($descriptor->children()),
            ),
            $descriptor instanceof ListSchema => [['.*', $descriptor->items(), $type->items, null]],
            default => [],
        };
        foreach ($parts as [$suffix, $part, $partType, $partGuard]) {
            if ($partType !== null) {
                self::add($rules, $path . $suffix, [
                    ...self::presence($part->isSometimes(), $part->isRequired(), $part->isNullable(), $partGuard),
                    ...$this->typeRules($partType),
                    ...$part->declaredRules(),
                ]);
                $inner = self::guard($path . $suffix, $partGuard, $suffix === '.*', $part->isSometimes(), $part->isRequired(), $part->isNullable());
                $this->nested($partType, $part, $path . $suffix, $inner, $rules, $kind, $revision, $converters);
            }
        }
        if ($descriptor instanceof ResourceSchema) {
            $this->resource($descriptor->class(), $path, $guard, $rules, $kind, $revision, $converters);
        }
    }

    /**
     * The rules of the nested resource `$resource`: its shape of the same declaration kind at `$revision`, its descriptors described without subject.
     *
     * @param class-string<Revisioned> $resource
     * @param array<string, list<mixed>> $rules
     */
    private function resource(string $resource, string $path, ?string $guard, array &$rules, DeclarationKind $kind, Revision $revision, Converters $converters): void
    {
        $this->level(
            $this->shapes->for($resource, $kind, $revision),
            $resource::describeRevisioned($kind, new Probe($resource)),
            "{$path}.",
            $guard,
            $rules,
            $kind,
            $resource,
            $converters,
        );
    }

    /**
     * @return list<mixed>
     */
    private function typeRules(TypeDefinition $type): array
    {
        return ($this->mappers[$type->kind->value] ?? self::defaultMapper($type->kind))->rules($type);
    }

    /**
     * `sometimes`, `required`, `nullable` as declared (Laravel decides what they mean together); below a parent
     * the client may leave out or send `null`, `required_unless:<parent>,null` instead of `required`: required as soon
     * as the parent is sent and not `null`, an empty one included (`required_with` would take `{}` for absent).
     *
     * @return list<string>
     */
    private static function presence(bool $sometimes, bool $required, bool $nullable, ?string $guard): array
    {
        $rules = $sometimes ? ['sometimes'] : [];
        if ($required) {
            $rules[] = $guard === null ? 'required' : "required_unless:{$guard},null";
        }
        if ($nullable) {
            $rules[] = 'nullable';
        }

        return $rules;
    }

    /**
     * The guard of the children of the field at `$path`: itself when the client may leave it out or send `null` (not
     * required, `sometimes`, nullable, or under such a parent), else none. An item exists once sent: only a nullable
     * one guards its children.
     */
    private static function guard(string $path, ?string $guard, bool $item, bool $sometimes, bool $required, bool $nullable): ?string
    {
        $mayMiss = $item ? $nullable : $guard !== null || $sometimes || !$required || $nullable;

        return $mayMiss ? $path : null;
    }

    /**
     * The rules the first up conversion of the field needs, the one the client's value goes through
     * (`Converter::extraRules()`; the values of an enum it converts to). An explicit `up:` decides itself.
     *
     * @return list<mixed>
     */
    private static function conversionRules(FieldDefinition $field, Converters $converters): array
    {
        $step = $field->chain->bind($converters, [])->steps[0] ?? null;
        if ($step === null || $step->kind !== StepKind::Registered) {
            return [];
        }
        $rules = $step->converter?->extraRules() ?? [];
        if ($step->to->kind === Kind::Enum) {
            $rules[] = new In($step->to->values);
        }

        return $rules;
    }

    /**
     * Appends `$new` to the rules of `$path` (two fields under one name merge theirs; the check reports the collision).
     *
     * @param array<string, list<mixed>> $rules
     * @param list<mixed> $new
     */
    private static function add(array &$rules, string $path, array $new): void
    {
        $rules[$path] = [...$rules[$path] ?? [], ...$new];
    }

    private static function defaultMapper(Kind $kind): RuleMapper
    {
        return match ($kind) {
            Kind::String => new StringRules(),
            Kind::Int => new NumberRules('integer'),
            Kind::Float => new NumberRules('numeric'),
            Kind::Bool => new FixedRules(['boolean']),
            Kind::Date, Kind::DateTime => new DateRules(),
            Kind::Enum => new EnumRules(),
            Kind::List => new ArrayRules('list'),
            Kind::Object => new ArrayRules('array'),
            Kind::Custom, Kind::Any => new FixedRules(),
        };
    }
}
