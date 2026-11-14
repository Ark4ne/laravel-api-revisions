<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Testing;

use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use LogicException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

/**
 * Asserts that a JSON document is the response of a class at a revision (model.md D72): validated against its export
 * with closed objects, a list item by item. Needs `opis/json-schema` (a `LogicException` names it otherwise).
 */
final readonly class RevisionSchema
{
    /**
     * @param JsonSchemaExporter $exporter built `closed: true`
     */
    public function __construct(private JsonSchemaExporter $exporter) {}

    /**
     * Fails the test unless `$document` matches the response of `$class` at `$revision`: decoded JSON with objects as
     * `stdClass` (`json_decode()` without `associative`), a list validated item by item.
     *
     * @param class-string<Revisioned> $class
     */
    public function assertMatches(mixed $document, string $class, Revision $revision): void
    {
        if (!class_exists(Validator::class)) {
            throw new LogicException('The revision schema assertions need opis/json-schema: composer require --dev opis/json-schema.');
        }
        $schema = json_encode(JsonSchemaExporter::objects($this->exporter->export($class, DeclarationKind::Response, $revision)), JSON_THROW_ON_ERROR);
        $items = is_array($document) ? $document : [$document];
        $failures = [];
        foreach ($items as $index => $item) {
            $error = (new Validator())->validate($item, $schema)->error();
            if ($error !== null) {
                $failures[] = (is_array($document) ? "item {$index}: " : '') . json_encode((new ErrorFormatter())->format($error), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        }
        Assert::assertSame([], $failures, sprintf("The response does not match the schema of %s at %s:\n%s", $class, $revision->value, implode("\n", $failures)));
    }
}
