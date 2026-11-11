<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Revisions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Attribute;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Metadata of a revision, declared on its case: `#[Meta('Totals in cents', publishedAt: '2026-04-01')]`.
 * Informative only (docs, changelog, `Deprecation` / `Sunset` headers): it never drives behaviour (model.md D21).
 * Dates are `Y-m-d` days, checked when the attribute is read (`DefinitionException::invalidMetaDate()`).
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Meta
{
    public function __construct(
        public string $description = '',
        public ?string $publishedAt = null,
        public ?string $deprecatedAt = null,
        public ?string $sunsetAt = null,
    ) {
        self::assertDay('publishedAt', $publishedAt);
        self::assertDay('deprecatedAt', $deprecatedAt);
        self::assertDay('sunsetAt', $sunsetAt);
    }

    /**
     * Deprecated from `deprecatedAt` included, the day read in the timezone of `$now`.
     */
    public function isDeprecated(DateTimeInterface $now): bool
    {
        return self::reached($this->deprecatedAt, $now);
    }

    /**
     * Sunset from `sunsetAt` included, the day read in the timezone of `$now`.
     */
    public function isSunset(DateTimeInterface $now): bool
    {
        return self::reached($this->sunsetAt, $now);
    }

    private static function reached(?string $day, DateTimeInterface $now): bool
    {
        // Y-m-d strings compare as dates.
        return $day !== null && $now->format('Y-m-d') >= $day;
    }

    private static function assertDay(string $argument, ?string $day): void
    {
        if ($day === null) {
            return;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw DefinitionException::invalidMetaDate($argument, $day);
        }
    }
}
