<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Export;

use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use DateTimeInterface;

/**
 * The JSON Schema (draft 2020-12) of a declaring class for one declaration kind at one revision (model.md §11, D67):
 * read from its `Shape` only, so the export, the projection, the validation and the lock agree by construction. Nested
 * resources go to `$defs`, read from the same `Shapes`.
 *
 * @implements SchemaVisitor<array<string, mixed>>
 */
final readonly class JsonSchemaExporter implements SchemaVisitor
{
    public const string DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    public function __construct(private Shapes $shapes = new Shapes()) {}

    /**
     * The document of the declaration `$kind` of `$class` at `$revision`.
     *
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    public function export(string $class, DeclarationKind $kind, Revision $revision): array
    {
        return $this->visit($this->shapes->for($class, $kind, $revision));
    }

    /**
     * The document of `$shape`, the shape of a declaring class: its own nested resources are referenced as `'#'`, so
     * the shape of another class would leave a dangling `$ref`.
     *
     * @return array<string, mixed>
     */
    public function visit(Shape $shape): array
    {
        $document = ['$schema' => self::DIALECT];
        if ($shape->class !== null) {
            $document['title'] = $shape->class;
        }
        $document['$comment'] = self::comment($shape);
        $components = new Components($shape->kind->value ?? 'object', (string) $shape->revision->value);
        $document += $this->object($shape, $shape, $components, '');
        if ($components->all() !== []) {
            $document['$defs'] = $components->all();
        }

        return $document;
    }

    /**
     * The keywords the type itself gives, `null` accepted when `$nullable`, before the keywords of its rules
     * (`TypeDefinition::$jsonSchema`) and its documentation: what `schema-override` compares the rule keywords with
     * (D36). A nested resource gives its `$ref`; a component stays inline (D70).
     *
     * @return array<string, mixed>
     */
    public function typeKeywords(TypeDefinition $type, bool $nullable = false): array
    {
        return self::nullable($this->keywords($type, null, new Components('', ''), ''), $type, $nullable);
    }

    /**
     * The object of the fields of `$shape`, each with its presence (D67).
     *
     * @param string $prefix the path of the object in the document, `''` at the top level
     *
     * @return array<string, mixed>
     */
    private function object(Shape $shape, ?Shape $root, Components $components, string $prefix): array
    {
        $properties = [];
        $required = [];
        foreach ($shape->fields as $field) {
            $properties[$field->name] = $this->field($field, $root, $components, self::path($prefix, $field->headKey));
            $always = $shape->direction === Direction::Down ? !$field->conditional : $field->required && !$field->sometimes;
            if ($always) {
                $required[] = $field->name;
            }
        }
        $object = ['type' => 'object'];
        if ($properties !== []) {
            $object['properties'] = $properties;
        }
        if ($required !== []) {
            $object['required'] = $required;
        }

        return $object;
    }

    /**
     * A field at the revision of its shape: its children and items read from the field definitions where the head is
     * in force, from its type where the schema of a `was()` is.
     *
     * @param string $path its head key, dotted from the top level (`lines.*` for items)
     *
     * @return array<string, mixed>
     */
    private function field(FieldDefinition $field, ?Shape $root, Components $components, string $path): array
    {
        $head = $field->schemaChange === null;
        $keywords = $this->keywords($field->type, $root, $components, $path, $head ? $field->children : null, $head ? $field->items : null);

        return self::finish($keywords, $field->type, $field->nullable);
    }

    /**
     * The typed keywords of `$type`; in a document (`$root`), a component is defined in `$components` and referenced
     * (D70).
     *
     * @return array<string, mixed>
     */
    private function keywords(TypeDefinition $type, ?Shape $root, Components $components, string $path, ?Shape $children = null, ?FieldDefinition $items = null): array
    {
        $keywords = match ($type->kind) {
            Kind::String => self::filter([
                'type' => 'string',
                'minLength' => $type->min,
                'maxLength' => $type->max,
                'format' => $type->format,
                'pattern' => $type->pattern === null ? null : RegexPattern::of($type->pattern),
                '$comment' => $type->pattern !== null && RegexPattern::of($type->pattern) === null ? "PCRE {$type->pattern}" : null,
            ]),
            Kind::Int => self::filter(['type' => 'integer', 'minimum' => $type->min, 'maximum' => $type->max]),
            Kind::Float => self::filter(['type' => 'number', 'minimum' => $type->min, 'maximum' => $type->max]),
            Kind::Bool => ['type' => 'boolean'],
            Kind::Date => self::date($type->format, 'Y-m-d', 'date'),
            Kind::DateTime => self::date($type->format, DateTimeInterface::RFC3339, 'date-time'),
            Kind::Enum => ['type' => $type->backing === Kind::Int ? 'integer' : 'string', 'enum' => $type->values],
            Kind::List => self::filter([
                'type' => 'array',
                'minItems' => $type->min,
                'maxItems' => $type->max,
                'items' => match (true) {
                    $items !== null => $this->field($items, $root, $components, "{$path}.*"),
                    $type->items !== null => self::finish($this->keywords($type->items, $root, $components, "{$path}.*"), $type->items, $type->items->nullable),
                    default => null,
                },
            ]),
            Kind::Object => match (true) {
                $type->resource !== null => ['$ref' => $this->reference($type->resource, $root, $components, $path)],
                $children !== null => $this->object($children, $root, $components, $path),
                default => $this->children($type, $root, $components, $path),
            },
            Kind::Custom => $type->fragment,
            Kind::Any => [],
        };
        $component = $type->kind === Kind::Custom ? $type->name : ($type->resource === null ? $type->component : null);
        if ($component === null || $root === null) {
            return $keywords;
        }
        $components->define($component, $keywords, $path);

        return ['$ref' => Components::reference($component)];
    }

    /**
     * An object typed only: its children never required, nullable as their types say (the schema of a `was()` in force,
     * D67, D68).
     *
     * @return array<string, mixed>
     */
    private function children(TypeDefinition $type, ?Shape $root, Components $components, string $path): array
    {
        $properties = [];
        foreach ($type->children as $name => $child) {
            $properties[$name] = self::finish($this->keywords($child, $root, $components, self::path($path, $name)), $child, $child->nullable);
        }

        return $properties === [] ? ['type' => 'object'] : ['type' => 'object', 'properties' => $properties];
    }

    /**
     * The `$ref` of a nested resource: `#` for the class exported, else its definition, added once (cycles end there).
     *
     * @param class-string $class
     */
    private function reference(string $class, ?Shape $root, Components $components, string $path): string
    {
        if ($root?->class === $class) {
            return '#';
        }
        $name = str_replace('\\', '.', $class);
        if ($root !== null && $root->kind !== null && !$components->has($name)) {
            $components->reserve($name);
            $components->fill($name, $this->object($this->shapes->for($class, $root->kind, $root->revision), $root, $components, $path));
        }

        return Components::reference($name);
    }

    private static function path(string $prefix, string $key): string
    {
        return $prefix === '' ? $key : "{$prefix}.{$key}";
    }

    /**
     * Its presence aside, a field's schema: `null` accepted when nullable, its documentation, then the keywords of its
     * rules, last (D36).
     *
     * @param array<string, mixed> $keywords
     *
     * @return array<string, mixed>
     */
    private static function finish(array $keywords, TypeDefinition $type, bool $nullable): array
    {
        return [
            ...self::nullable($keywords, $type, $nullable),
            ...self::filter(['description' => $type->description, 'examples' => $type->examples === [] ? null : $type->examples, 'deprecated' => $type->deprecated ? true : null]),
            ...$type->jsonSchema,
        ];
    }

    /**
     * `$keywords` accepting `null` when `$nullable`: added to a string `type` (and to its `enum`), else `anyOf` (D67).
     *
     * @param array<string, mixed> $keywords
     *
     * @return array<string, mixed>
     */
    private static function nullable(array $keywords, TypeDefinition $type, bool $nullable): array
    {
        if ($nullable && $type->kind !== Kind::Any) {
            if ($type->kind === Kind::Custom || $type->resource !== null || !is_string($keywords['type'] ?? null)) {
                $keywords = ['anyOf' => [$keywords, ['type' => 'null']]];
            } else {
                $keywords['type'] = [$keywords['type'], 'null'];
                if (isset($keywords['enum']) && is_array($keywords['enum'])) {
                    $keywords['enum'][] = null;
                }
            }
        }

        return $keywords;
    }

    /**
     * A date string in the PHP `$format`: the JSON Schema `$known` format for `$standard`, else a pattern derived from
     * it when it has one, its PHP format in `$comment` (D67).
     *
     * @return array<string, mixed>
     */
    private static function date(?string $format, string $standard, string $known): array
    {
        if ($format === null || $format === $standard || ($known === 'date-time' && $format === DateTimeInterface::RFC3339_EXTENDED)) {
            return ['type' => 'string', 'format' => $known];
        }

        return self::filter(['type' => 'string', 'pattern' => DatePattern::of($format), '$comment' => "PHP date format {$format}"]);
    }

    /**
     * The kind and the revision of the document, with the `meta()` of the revision.
     */
    private static function comment(Shape $shape): string
    {
        $meta = $shape->revision->meta();
        $comment = sprintf('%s at %s', $shape->kind->value ?? 'object', $shape->revision->value);
        if ($meta->description !== '') {
            $comment .= ': ' . $meta->description;
        }
        $days = array_filter(['published' => $meta->publishedAt, 'deprecated' => $meta->deprecatedAt, 'sunset' => $meta->sunsetAt], static fn(?string $day): bool => $day !== null);
        if ($days !== []) {
            $comment .= ' (' . implode(', ', array_map(static fn(string $what, string $day): string => "{$what} {$day}", array_keys($days), $days)) . ')';
        }

        return $comment;
    }

    /**
     * `$keywords` without the `null` ones.
     *
     * @param array<string, mixed> $keywords
     *
     * @return array<string, mixed>
     */
    private static function filter(array $keywords): array
    {
        return array_filter($keywords, static fn(mixed $value): bool => $value !== null);
    }
}
