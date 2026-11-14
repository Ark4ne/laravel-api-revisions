<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Checking\Fields;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;

/**
 * A warning per keyword a `DescribesSchema` rule gives with another value than the type of its field
 * (`JsonSchemaExporter::typeKeywords()`, `null` included for a nullable field): the export keeps the rule's (model.md
 * D36, D65), once per kind, field and keyword, at the newest revision it holds. The children of a `was()` schema are
 * not walked (the head ones are).
 */
final readonly class SchemaOverride implements Check
{
    public const string CODE = 'schema-override';

    public function run(CheckContext $context): void
    {
        $exporter = new JsonSchemaExporter();
        $reported = [];
        foreach ($context->kinds() as $kind) {
            foreach (array_reverse($context->order()) as $revision) {
                foreach (Fields::of($context->shape($kind, $revision)) as $path => $field) {
                    $typed = $exporter->typeKeywords($field->type, $field->nullable);
                    foreach ($field->type->jsonSchema as $keyword => $value) {
                        if (!array_key_exists($keyword, $typed) || self::same($typed[$keyword], $value) || isset($reported[$kind->value][$path][$keyword])) {
                            continue;
                        }
                        $reported[$kind->value][$path][$keyword] = true;
                        $context->warning(
                            self::CODE,
                            sprintf(
                                "The rule keyword '%s' of '%s' (%s) overrides %s with %s.",
                                $keyword,
                                $path,
                                $kind->value,
                                json_encode($typed[$keyword], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                                json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                            ),
                            $path,
                            $revision,
                        );
                    }
                }
            }
        }
    }

    /**
     * Whether two keyword values are the same, a number whatever its PHP type (`5` and `5.0`).
     */
    private static function same(mixed $typed, mixed $value): bool
    {
        if ((is_int($typed) || is_float($typed)) && (is_int($value) || is_float($value))) {
            return (float) $typed === (float) $value;
        }

        return $typed === $value;
    }
}
