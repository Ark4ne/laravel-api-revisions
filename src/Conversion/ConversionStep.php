<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion;

use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Exceptions\ConversionException;

/**
 * One step of a `ConversionChain`: between two consecutive shapes, from the type of one to the type of the other, for
 * the `was()` at index `$change`. Holds references only (its kind, key, types and `was` index); its converter is
 * attached by `ConversionChain::bind()` and never serialized, so a bound chain serializes as its planned form.
 */
final readonly class ConversionStep
{
    /**
     * @param string $key `Converters::key()` of the pair and direction (`int>string:down`), whatever the kind
     * @param int $change the index in the field's history (newest first) of the `was()` this step crosses: its
     *                    revision is the older shape of the step
     * @param Converter|null $converter once bound; never for a missing step
     */
    public function __construct(
        public StepKind $kind,
        public string $key,
        public TypeDefinition $from,
        public TypeDefinition $to,
        public int $change,
        public ?Converter $converter = null,
    ) {}

    /**
     * This step with its converter attached.
     */
    public function withConverter(?Converter $converter): self
    {
        return new self($this->kind, $this->key, $this->from, $this->to, $this->change, $converter);
    }

    /**
     * Converts a normalized value of `$from`, `$subject` passed to a closure. Throws `ConversionException::missing()`
     * without converter (a missing step, or a step not bound).
     */
    public function apply(mixed $value, mixed $subject = null): mixed
    {
        $converter = $this->converter ?? throw ConversionException::missing($this->key);

        return $converter->convert($value, $subject);
    }

    /**
     * The references only: a bound converter (possibly a closure) is never serialized.
     *
     * @return array{kind: StepKind, key: string, from: TypeDefinition, to: TypeDefinition, change: int}
     */
    public function __serialize(): array
    {
        return ['kind' => $this->kind, 'key' => $this->key, 'from' => $this->from, 'to' => $this->to, 'change' => $this->change];
    }

    /**
     * @param array{kind: StepKind, key: string, from: TypeDefinition, to: TypeDefinition, change: int} $data
     */
    public function __unserialize(array $data): void
    {
        $this->kind = $data['kind'];
        $this->key = $data['key'];
        $this->from = $data['from'];
        $this->to = $data['to'];
        $this->change = $data['change'];
        $this->converter = null;
    }
}
