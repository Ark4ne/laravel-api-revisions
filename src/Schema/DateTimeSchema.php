<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use DateTimeImmutable;

/**
 * A date and time: serialized with its format, RFC 3339 unless configured (model.md D29). Accepts a `DateTimeInterface`, a non-empty string `DateTimeImmutable` parses, or an int timestamp (UTC).
 *
 * @extends Descriptor<DateTimeImmutable>
 */
final class DateTimeSchema extends Descriptor
{
    /**
     * `null` until configured: the configured default (model.md D29).
     *
     * @var non-empty-string|null
     */
    private ?string $format = null;

    /**
     * A PHP date format (`DateTimeInterface::format()`).
     *
     * @param non-empty-string $format
     */
    public function format(string $format): self
    {
        $clone = clone $this;
        $clone->format = $format;

        return $clone;
    }

    public function configure(Configuration $configuration): static
    {
        $clone = clone $this;
        $clone->format ??= $configuration->dateTimeFormat;

        return $clone;
    }

    public function normalize(mixed $value): mixed
    {
        return Dates::normalize($value) ?? new InvalidValue($value, Kind::DateTime, null);
    }

    /**
     * @param DateTimeImmutable $value
     */
    public function serialize(mixed $value): mixed
    {
        return $value->format($this->resolvedFormat());
    }

    /**
     * The format serialized and exported: the explicit or configured one, else the default of `Configuration`.
     *
     * @return non-empty-string
     */
    private function resolvedFormat(): string
    {
        return $this->format ?? (new Configuration())->dateTimeFormat;
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::of(Kind::DateTime)->withFormat($this->resolvedFormat());
    }
}
