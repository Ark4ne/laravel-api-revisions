<?php

declare(strict_types=1);

namespace Test\Unit\Testing;

use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Testing\RevisionSchema;
use PHPUnit\Framework\AssertionFailedError;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\TestCase;

final class RevisionSchemaTest extends TestCase
{
    public function test_a_document_matching_the_closed_schema_passes(): void
    {
        self::schema()->assertMatches((object) ['full_name' => 'Ada'], CustomerDeclaration::class, ApiRevision::Winter25);
        $this->addToAssertionCount(1);
    }

    public function test_a_field_outside_the_shape_fails(): void
    {
        $failure = self::failure(static fn() => self::schema()->assertMatches((object) ['full_name' => 'Ada', 'leaked' => true], CustomerDeclaration::class, ApiRevision::Winter25));

        self::assertStringStartsWith("The response does not match the schema of Test\\Fixtures\\Declarations\\CustomerDeclaration at winter-25:\n{", $failure);
        self::assertStringContainsString('Additional object properties are not allowed: leaked', $failure);
    }

    public function test_a_list_is_validated_item_by_item_each_failure_naming_its_item(): void
    {
        $failure = self::failure(static fn() => self::schema()->assertMatches(
            [(object) ['full_name' => 'Ada'], (object) ['name' => 'Grace'], (object) ['full_name' => 'Alan'], (object) ['full_name' => 1]],
            CustomerDeclaration::class,
            ApiRevision::Winter25,
        ));

        self::assertStringContainsString("\nitem 1: {", $failure);
        self::assertStringContainsString("\nitem 3: {", $failure);
        self::assertStringNotContainsString('item 0:', $failure);
        self::assertStringNotContainsString('item 2:', $failure);
    }

    public function test_an_empty_list_passes(): void
    {
        self::schema()->assertMatches([], CustomerDeclaration::class, ApiRevision::Atlas);
        $this->addToAssertionCount(1);
    }

    private static function schema(): RevisionSchema
    {
        return new RevisionSchema(new JsonSchemaExporter(new Shapes(), closed: true));
    }

    /**
     * The message of the assertion `$assertion` fails with.
     */
    private static function failure(callable $assertion): string
    {
        try {
            $assertion();
        } catch (AssertionFailedError $failure) {
            return $failure->getMessage();
        }

        self::fail('The assertion passed.');
    }
}
