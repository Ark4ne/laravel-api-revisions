<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Conversion;

/**
 * Converts a normalized value of one type to the value of another (model.md §5). Strategy of the registry
 * `Converters`; a converter never reads the subject.
 */
interface Converter
{
    /**
     * @param mixed $value a normalized value of the source type, never `null` (the pipeline handles it first)
     *
     * @return mixed a normalized value of the target type
     */
    public function convert(mixed $value): mixed;

    /**
     * Whether every value converts without loss of information; a lossy-capable automatic conversion without closure
     * gets a `conversion-lossy` warning (model.md D25).
     */
    public function lossless(): bool;

    /**
     * Validation rules the older shape adds on the request side so that an up conversion only sees valid input
     * (`numeric`, `integer`), opaque to the core.
     *
     * @return list<mixed>
     */
    public function extraRules(): array;
}
