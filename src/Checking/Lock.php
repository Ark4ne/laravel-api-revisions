<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Exceptions\LockException;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\Revisioned;

/**
 * The lock of the published revisions (model.md §9, D11, D69): one `<value>.json` per locked revision under
 * `$directory`, committed. Captures, writes and reads snapshots; the checks `lock-mismatch` and `lock-order-changed`
 * compare them with the declarations.
 */
final readonly class Lock
{
    /**
     * The documentation keywords left out of a snapshot, wherever a schema holds them (D69).
     */
    public const array DOCUMENTATION = ['title', 'description', 'examples', 'deprecated'];

    public function __construct(public string $directory) {}

    /**
     * The backed values of the locked revisions, sorted; none when the directory does not exist.
     *
     * @return list<string>
     */
    public function locked(): array
    {
        $entries = is_dir($this->directory) ? scandir($this->directory) : false;
        $values = [];
        foreach ($entries === false ? [] : $entries as $entry) {
            if (str_ends_with($entry, '.json') && is_file($this->path(substr($entry, 0, -5)))) {
                $values[] = substr($entry, 0, -5);
            }
        }
        sort($values);

        return $values;
    }

    public function has(Revision $revision): bool
    {
        return is_file($this->path((string) $revision->value));
    }

    /**
     * The snapshot of the revision `$value`; `LockException::invalidFile()` when its file is not one.
     */
    public function read(string $value): LockedRevision
    {
        $path = $this->path($value);
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw LockException::invalidFile($path);
        }

        return LockedRevision::fromJson($json, $path);
    }

    /**
     * Writes the snapshot (creating the directory); `LockException::unwritable()` when it cannot.
     */
    public function write(LockedRevision $snapshot): void
    {
        $path = $this->path($snapshot->revision);
        if ((!is_dir($this->directory) && !@mkdir($this->directory, 0o777, true)) || @file_put_contents($path, $snapshot->toJson()) === false) {
            throw LockException::unwritable($path);
        }
    }

    /**
     * The snapshot of `$revision`: the structure of every declaration kind of every class in `$classes`, exported by
     * `$exporter`, and the revision before it.
     *
     * @param list<class-string<Revisioned>> $classes
     */
    public static function capture(Revision $revision, array $classes, JsonSchemaExporter $exporter): LockedRevision
    {
        $snapshot = [];
        foreach ($classes as $class) {
            foreach ($class::declarationKinds() as $kind) {
                $snapshot[$class][$kind->value] = self::normalize($exporter->export($class, $kind, $revision));
            }
        }
        $previous = $revision->previous();

        return new LockedRevision((string) $revision->value, $previous === null ? null : (string) $previous->value, $snapshot);
    }

    /**
     * The structure of an exported document (D69): without its root `$comment` (the revision `meta()`) nor the
     * documentation keywords of any schema it holds (a property named `title` stays), its keys sorted (lists keep their
     * order).
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $document): array
    {
        unset($document['$comment']);

        return self::structure($document);
    }

    /**
     * A schema without its documentation keywords, recursively through the schemas it holds, its keys sorted.
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
            if (in_array($keyword, self::DOCUMENTATION, true)) {
                continue;
            }
            $structure[$keyword] = match (true) {
                !is_array($value) => $value,
                in_array($keyword, ['properties', '$defs'], true) => self::sorted(array_map(static fn(mixed $child): mixed => is_array($child) ? self::structure($child) : $child, $value)),
                in_array($keyword, ['anyOf', 'oneOf', 'allOf', 'prefixItems'], true) && array_is_list($value) => array_map(static fn(mixed $child): mixed => is_array($child) ? self::structure($child) : $child, $value),
                in_array($keyword, ['items', 'not', 'contains', 'additionalProperties'], true) => self::structure($value),
                default => self::sorted($value),
            };
        }
        ksort($structure);

        return $structure;
    }

    /**
     * A map with its keys sorted, recursively (a list keeps its order).
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        $value = array_map(static fn(mixed $item): mixed => is_array($item) ? self::sorted($item) : $item, $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function path(string $value): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $value . '.json';
    }
}
