<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Exceptions\LockException;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;

/**
 * The snapshot of one revision (model.md §9, D69): its backed value, the backed value of the revision before it in
 * `order()` when it was locked (`null` for the oldest), and the structural JSON Schema of every class, by class then
 * by declaration kind. Immutable.
 */
final readonly class LockedRevision
{
    /**
     * @param array<string, array<string, array<string, mixed>>> $classes normalized schemas, by class then kind
     */
    public function __construct(
        public string $revision,
        public ?string $previous,
        public array $classes,
    ) {}

    /**
     * The snapshot read from the JSON of a lock file at `$path`; `LockException::invalidFile()` when it is not one.
     */
    public static function fromJson(string $json, string $path): self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !array_key_exists('previous', $data) || !(is_string($data['previous']) || $data['previous'] === null)
            || !isset($data['classes']) || !is_array($data['classes']) || array_is_list($data['classes']) && $data['classes'] !== []) {
            throw LockException::invalidFile($path);
        }
        $classes = [];
        foreach ($data['classes'] as $class => $kinds) {
            if (!is_array($kinds) || array_is_list($kinds)) {
                throw LockException::invalidFile($path);
            }
            foreach ($kinds as $kind => $schema) {
                if (!is_array($schema) || array_is_list($schema) && $schema !== []) {
                    throw LockException::invalidFile($path);
                }
                /** @var array<string, mixed> $schema decoded from a JSON object */
                $classes[(string) $class][(string) $kind] = $schema;
            }
        }

        return new self(basename($path, '.json'), $data['previous'], $classes);
    }

    /**
     * The JSON of the lock file: pretty-printed, classes and kinds sorted, ending with a new line.
     */
    public function toJson(): string
    {
        $classes = $this->classes;
        ksort($classes);
        $classes = array_map(static function (array $kinds): object {
            ksort($kinds);

            return (object) array_map(static fn(array $schema): object => JsonSchemaExporter::objects($schema), $kinds);
        }, $classes);

        return json_encode(
            ['previous' => $this->previous, 'classes' => (object) $classes],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
