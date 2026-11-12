<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Configuration;

/**
 * The options of the core, given to it explicitly (never read globally). The Laravel layer builds it from
 * `config/revisions.php` (US 8.1).
 */
final readonly class Configuration
{
    /**
     * @param non-empty-string $dateFormat the format of `Schema::date()` without `format()` (model.md D29)
     * @param non-empty-string $dateTimeFormat the format of `Schema::dateTime()` without `format()`, RFC 3339 by default
     * @param MissingDefault $missingDefault the default of a `required()->since(R)` field without `default:` (D47)
     * @param bool $strictValues value errors throw (`true`, the default of the core) or are cast and reported
     *                           (model.md D19, D53; the Laravel layer gives `app.debug`)
     */
    public function __construct(
        public string $dateFormat = 'Y-m-d',
        public string $dateTimeFormat = 'Y-m-d\TH:i:sP',
        public MissingDefault $missingDefault = MissingDefault::Error,
        public bool $strictValues = true,
    ) {}
}
