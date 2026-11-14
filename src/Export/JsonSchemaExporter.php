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
     * @return array<string, mixed>
     */
    public function visit(Shape $shape): array
    {
        $document = ['$schema' => self::DIALECT];
        if ($shape->class !== null) {
            $document['title'] = $shape->class;
        }
        $document['$comment'] = self::comment($shape);
        $defs = [];
        $document += $this->object($shape, $shape, $defs);
        if ($defs !== []) {
            $document['$defs'] = $defs;
        }

        return $document;
    }

    /**
     * The keywords the type itself gives, `null` accepted when `$nullable`, before the keywords of its rules
     * (`TypeDefinition::$jsonSchema`) and its documentation: what `schema-override` compares the rule keywords with
     * (D36). A nested resource gives its `$ref`.
     *
     * @return array<string, mixed>
     */
    public function typeKeywords(TypeDefinition $type, bool $nullable = false): array
    {
        $defs = [];

        return self::nullable($this->keywords($type, null, $defs), $type, $nullable);
    }

    /**
     * The object of the fields of `$shape`, each with its presence (D67).
     *
     * @param array<string, array<string, mixed>> $defs the definitions of the nested resources, filled
     *
     * @return array<string, mixed>
     */
    private function object(Shape $shape, ?Shape $root, array &$defs): array
    {
        $properties = [];
        $required = [];
        foreach ($shape->fields as $field) {
            $properties[$field->name] = $this->field($field, $root, $defs);
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
     * @param array<string, array<string, mixed>> $defs
     *
     * @return array<string, mixed>
     */
    private function field(FieldDefinition $field, ?Shape $root, array &$defs): array
    {
        $head = $field->schemaChange === null;
        $keywords = $this->keywords($field->type, $root, $defs, $head ? $field->children : null, $head ? $field->items : null);

        return self::finish($keywords, $field->type, $field->nullable);
    }

    /**
     * The typed keywords of `$type`.
     *
     * @param array<string, array<string, mixed>> $defs
     *
     * @return array<string, mixed>
     */
    private function keywords(TypeDefinition $type, ?Shape $root, array &$defs, ?Shape $children = null, ?FieldDefinition $items = null): array
    {
        return match ($type->kind) {
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
                    $items !== null => $this->field($items, $root, $defs),
                    $type->items !== null => self::finish($this->keywords($type->items, $root, $defs), $type->items, $type->items->nullable),
                    default => null,
                },
            ]),
            Kind::Object => $type->resource !== null
                ? ['$ref' => $this->reference($type->resource, $root, $defs)]
                : ($children !== null ? $this->object($children, $root, $defs) : $this->children($type, $root, $defs)),
            Kind::Custom => $type->fragment,
            Kind::Any => [],
        };
    }

    /**
     * An object typed only: its children never required, nullable as their types say (the schema of a `was()` in force,
     * D67, D68).
     *
     * @param array<string, array<string, mixed>> $defs
     *
     * @return array<string, mixed>
     */
    private function children(TypeDefinition $type, ?Shape $root, array &$defs): array
    {
        $properties = [];
        foreach ($type->children as $name => $child) {
            $properties[$name] = self::finish($this->keywords($child, $root, $defs), $child, $child->nullable);
        }

        return $properties === [] ? ['type' => 'object'] : ['type' => 'object', 'properties' => $properties];
    }

    /**
     * The `$ref` of a nested resource: `#` for the class exported, else its definition, added once (cycles end there).
     *
     * @param class-string $class
     * @param array<string, array<string, mixed>> $defs
     */
    private function reference(string $class, ?Shape $root, array &$defs): string
    {
        if ($root?->class === $class) {
            return '#';
        }
        $name = str_replace('\\', '.', $class);
        if ($root !== null && $root->kind !== null && !isset($defs[$name])) {
            $defs[$name] = [];
            $defs[$name] = $this->object($this->shapes->for($class, $root->kind, $root->revision), $root, $defs);
        }

        return '#/$defs/' . $name;
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
