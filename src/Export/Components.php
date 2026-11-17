<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Export;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;

/**
 * The `$defs` of one exported document: its nested resources and its components, each defined
 * once under its name and referenced `#/$defs/<name>`. A component defined again with another body is a declaration
 * error. Filled by the `JsonSchemaExporter` while it walks one document.
 */
final class Components
{
    /**
     * @var array<string, array<string, mixed>> the definitions, by name
     */
    private array $definitions = [];

    /**
     * @var array<string, true> the names reserved and not defined yet
     */
    private array $reserved = [];

    /**
     * @param string $kind the declaration kind of the document, for the errors
     * @param string $revision the backed value of the revision of the document, for the errors
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $revision,
    ) {}

    /**
     * The `$ref` of `$name` (a JSON Pointer, `~` and `/` escaped).
     */
    public static function reference(string $name): string
    {
        return '#/$defs/' . str_replace(['~', '/'], ['~0', '~1'], $name);
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /**
     * Reserves `$name` for a body filled later (a nested resource, whose cycles end at its reference).
     */
    public function reserve(string $name): void
    {
        $this->definitions[$name] ??= [];
        $this->reserved[$name] = true;
    }

    /**
     * Fills the name reserved by `reserve()` with the body of its nested resource.
     *
     * @param array<string, mixed> $schema
     */
    public function fill(string $name, array $schema): void
    {
        unset($this->reserved[$name]);
        $this->definitions[$name] = $schema;
    }

    /**
     * Defines the component `$name` as `$schema`, used by `$field`: the first definition holds; another body, or a
     * name a nested resource holds, throws `DefinitionException::componentConflict()`.
     *
     * @param array<string, mixed> $schema
     */
    public function define(string $name, array $schema, string $field): void
    {
        if (!isset($this->definitions[$name])) {
            $this->definitions[$name] = $schema;
        } elseif (isset($this->reserved[$name]) || $this->definitions[$name] !== $schema) {
            throw DefinitionException::componentConflict($name, $field, $this->kind, $this->revision);
        }
    }

    /**
     * The definitions, in the order they were first used.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
