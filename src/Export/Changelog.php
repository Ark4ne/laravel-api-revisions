<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Export;

use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Ark4ne\ApiRevisions\Schema\ClassLifetime;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;

/**
 * The changelog of the revisions (model.md §11, D71): for each revision, what it changes on each class and declaration
 * kind against the revision before it — fields matched by their head key path, so a rename is known from the history,
 * never guessed — rendered in Markdown with the revisions' `meta()`.
 */
final readonly class Changelog
{
    private JsonSchemaExporter $exporter;

    public function __construct(private Shapes $shapes = new Shapes())
    {
        $this->exporter = new JsonSchemaExporter($shapes);
    }

    /**
     * What `$revision` changes on the declaration `$kind` of `$class` against the revision before it; none for the
     * oldest. A class whose lifetime (`HasLifetime`, model.md D81) starts at `$revision` is one change `Added` of path
     * `''`, one ending at the revision before is one change `Removed` of path `''`; none where it exists on neither side.
     *
     * @param class-string<Revisioned> $class
     *
     * @return list<Change>
     */
    public function changes(string $class, DeclarationKind $kind, Revision $revision): array
    {
        $previous = $revision->previous();
        if ($previous === null) {
            return [];
        }
        $before = ClassLifetime::exists($class, $previous);
        $after = ClassLifetime::exists($class, $revision);
        if (!$before || !$after) {
            return match (true) {
                $after => [new Change(ChangeKind::Added, '')],
                $before => [new Change(ChangeKind::Removed, '')],
                default => [],
            };
        }
        $changes = [];
        $this->level(
            self::byHeadKey($this->shapes->for($class, $kind, $previous)->fields),
            self::byHeadKey($this->shapes->for($class, $kind, $revision)->fields),
            '',
            '',
            $changes,
        );

        return $changes;
    }

    /**
     * The Markdown changelog of `$classes` for every revision after `$from` up to `$to` included, newest first.
     *
     * @param list<class-string<Revisioned>> $classes
     */
    public function markdown(array $classes, Revision $from, Revision $to): string
    {
        $sections = [];
        foreach (array_reverse(RevisionOrder::between($from, $to)) as $revision) {
            if ($revision === $from) {
                continue;
            }
            $section = ['## ' . $revision->value, ...self::meta($revision)];
            $changed = false;
            foreach ($classes as $class) {
                foreach ($class::declarationKinds() as $kind) {
                    $changes = $this->changes($class, $kind, $revision);
                    if ($changes !== []) {
                        $changed = true;
                        $section[] = "### {$class} ({$kind->value})";
                        $section[] = implode("\n", array_map(static fn(Change $change): string => '- ' . $change->describe(), $changes));
                    }
                }
            }
            if (!$changed) {
                $section[] = 'No change.';
            }
            $sections[] = implode("\n\n", $section);
        }

        return implode("\n\n", ['# Changelog', ...$sections]) . "\n";
    }

    /**
     * Compares the fields of one level (the top level, the children of an object, the items of a list), matched by
     * head key: renamed, type changed and walked when on both sides, then added, then removed.
     *
     * @param array<string, FieldDefinition> $before
     * @param array<string, FieldDefinition> $after
     * @param list<Change> $changes
     */
    private function level(array $before, array $after, string $prefixBefore, string $prefixAfter, array &$changes): void
    {
        $added = [];
        foreach ($after as $headKey => $field) {
            $path = self::path($prefixAfter, $field->name);
            $old = $before[$headKey] ?? null;
            if ($old === null) {
                $added[] = new Change(ChangeKind::Added, $path);

                continue;
            }
            $oldPath = self::path($prefixBefore, $old->name);
            if ($field->name !== $old->name) {
                $changes[] = new Change(ChangeKind::Renamed, $path, $oldPath);
            }
            [$walkedBefore, $walkedAfter] = [self::walked($old), self::walked($field)];
            $shallow = $walkedBefore !== null && $walkedAfter !== null && ($walkedBefore instanceof Shape) === ($walkedAfter instanceof Shape);
            $typeBefore = $this->keywords($old, $shallow);
            $typeAfter = $this->keywords($field, $shallow);
            if ($typeBefore !== $typeAfter) {
                $changes[] = new Change(ChangeKind::TypeChanged, $path, before: $typeBefore, after: $typeAfter);
            }
            if ($shallow && $walkedBefore instanceof Shape && $walkedAfter instanceof Shape) {
                $this->level(self::byHeadKey($walkedBefore->fields), self::byHeadKey($walkedAfter->fields), $oldPath, $path, $changes);
            } elseif ($shallow && $walkedBefore instanceof FieldDefinition && $walkedAfter instanceof FieldDefinition) {
                $this->level(['*' => $walkedBefore], ['*' => $walkedAfter], $oldPath, $path, $changes);
            }
        }
        array_push($changes, ...$added);
        foreach ($before as $headKey => $field) {
            if (!isset($after[$headKey])) {
                $changes[] = new Change(ChangeKind::Removed, self::path($prefixBefore, $field->name));
            }
        }
    }

    /**
     * What is walked below a field where its head is in force: the shape of its children, or its items; `null` for a
     * leaf or under the schema of a `was()` (compared by its whole type).
     */
    private static function walked(FieldDefinition $field): Shape|FieldDefinition|null
    {
        return $field->schemaChange === null ? $field->children ?? $field->items : null;
    }

    /**
     * The keywords its type gives (D71); `$shallow`: without what is walked below it (`properties`, `required`,
     * `items`).
     *
     * @return array<string, mixed>
     */
    private function keywords(FieldDefinition $field, bool $shallow): array
    {
        $keywords = self::structure($this->exporter->typeKeywords($field->type, $field->nullable));
        if ($shallow) {
            // a walked field is a list or an object, never wrapped in `anyOf`: nullable adds `null` to its `type`
            unset($keywords['properties'], $keywords['required'], $keywords['items']);
        }

        return $keywords;
    }

    /**
     * `$schema` without the documentation keywords of the schemas it holds (its children, its items, the branches of
     * an `anyOf`).
     *
     * @param array<array-key, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function structure(array $schema): array
    {
        $structure = [];
        foreach ($schema as $keyword => $value) {
            $keyword = (string) $keyword;
            if (in_array($keyword, JsonSchemaExporter::DOCUMENTATION, true)) {
                continue;
            }
            $structure[$keyword] = match (true) {
                !is_array($value) => $value,
                $keyword === 'properties' => array_map(static fn(mixed $child): mixed => is_array($child) ? self::structure($child) : $child, $value),
                $keyword === 'items' => self::structure($value),
                $keyword === 'anyOf' => array_map(static fn(mixed $branch): mixed => is_array($branch) ? self::structure($branch) : $branch, $value),
                default => $value,
            };
        }

        return $structure;
    }

    /**
     * @param array<array-key, FieldDefinition> $fields
     *
     * @return array<string, FieldDefinition>
     */
    private static function byHeadKey(array $fields): array
    {
        $byHeadKey = [];
        foreach ($fields as $field) {
            $byHeadKey[$field->headKey] ??= $field;
        }

        return $byHeadKey;
    }

    private static function path(string $prefix, string $name): string
    {
        return $prefix === '' ? $name : "{$prefix}.{$name}";
    }

    /**
     * The paragraphs of the `meta()` of `$revision`: its description, then its days.
     *
     * @return list<string>
     */
    private static function meta(Revision $revision): array
    {
        $meta = $revision->meta();
        $days = array_filter(['Published' => $meta->publishedAt, 'deprecated' => $meta->deprecatedAt, 'sunset' => $meta->sunsetAt], static fn(?string $day): bool => $day !== null);
        $paragraphs = $meta->description === '' ? [] : [$meta->description];
        if ($days !== []) {
            $words = array_map(static fn(string $what, string $day): string => "{$what} {$day}", array_keys($days), $days);
            $paragraphs[] = ucfirst(implode(' · ', $words));
        }

        return $paragraphs;
    }
}
