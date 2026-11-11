<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Definition;

use BackedEnum;

/**
 * The type of a field at one revision: its kind, its constraints and its documentation. Public contract of the tools:
 * immutable, serializable, closure-free.
 */
final readonly class TypeDefinition
{
    /**
     * @param list<mixed> $examples JSON-ready values
     * @param class-string<BackedEnum>|null $enum the enum of an `Enum` kind
     * @param list<int|string> $values the backed values of the enum, in declaration order
     * @param TypeDefinition|null $items the type of the items of a `List` kind
     * @param array<string, TypeDefinition> $children the types of the children of an `Object` kind, by key
     * @param array<string, mixed> $jsonSchema JSON Schema keywords given by the rules (`DescribesSchema`), JSON-ready
     */
    private function __construct(
        public Kind $kind,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?string $format = null,
        public ?string $pattern = null,
        public ?string $description = null,
        public array $examples = [],
        public bool $deprecated = false,
        public ?string $enum = null,
        public ?Kind $backing = null,
        public array $values = [],
        public ?TypeDefinition $items = null,
        public array $children = [],
        public array $jsonSchema = [],
    ) {}

    public static function of(Kind $kind): self
    {
        return new self($kind);
    }

    /**
     * The minimum: the length of a string, the size of a list, the value of a number.
     */
    public function withMin(int|float|null $min): self
    {
        return $this->with(['min' => $min]);
    }

    /**
     * The maximum: the length of a string, the size of a list, the value of a number.
     */
    public function withMax(int|float|null $max): self
    {
        return $this->with(['max' => $max]);
    }

    /**
     * A JSON Schema format for a string (`email`), a PHP date format for a date.
     */
    public function withFormat(?string $format): self
    {
        return $this->with(['format' => $format]);
    }

    /**
     * A regular expression (PCRE, with delimiters) the string matches.
     */
    public function withPattern(?string $pattern): self
    {
        return $this->with(['pattern' => $pattern]);
    }

    public function withDescription(?string $description): self
    {
        return $this->with(['description' => $description]);
    }

    /**
     * @param scalar|array<mixed>|null $example a JSON-ready value
     */
    public function addExample(mixed $example): self
    {
        return $this->with(['examples' => [...$this->examples, $example]]);
    }

    /**
     * The enum of an `Enum` kind: its class, its backing kind (`Int` or `String`) and its backed values.
     *
     * @param class-string<BackedEnum> $enum
     * @param list<int|string> $values
     */
    public function withEnum(string $enum, Kind $backing, array $values): self
    {
        return $this->with(['enum' => $enum, 'backing' => $backing, 'values' => $values]);
    }

    /**
     * The type of the items of a `List` kind.
     */
    public function withItems(TypeDefinition $items): self
    {
        return $this->with(['items' => $items]);
    }

    /**
     * The types of the children of an `Object` kind, by key, in declaration order.
     *
     * @param array<string, TypeDefinition> $children
     */
    public function withChildren(array $children): self
    {
        return $this->with(['children' => $children]);
    }

    /**
     * JSON Schema keywords added to the export of the type.
     *
     * @param array<string, mixed> $jsonSchema
     */
    public function withJsonSchema(array $jsonSchema): self
    {
        return $this->with(['jsonSchema' => $jsonSchema]);
    }

    public function withDeprecated(bool $deprecated): self
    {
        return $this->with(['deprecated' => $deprecated]);
    }

    /**
     * A copy with some properties replaced (the properties are the constructor's parameters).
     *
     * @param array<string, mixed> $changes
     */
    private function with(array $changes): self
    {
        /** @phpstan-ignore argument.type (the keys are the constructor's parameters, the values their types) */
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
