<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Support\Diagnostic;
use Ark4ne\ApiRevisions\Support\Diagnostics;
use Ark4ne\ApiRevisions\Support\Severity;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\Fixtures\Declarations\StoreOrderDeclaration;
use Test\Fixtures\Revisions\IntRevision;
use Test\TestCase;

final class CheckContextTest extends TestCase
{
    public function test_an_error_names_the_checked_class_its_field_and_revision(): void
    {
        $diagnostics = new Diagnostics();
        $context = new CheckContext(ApiRevision::class, CountingDeclaration::class, new Compiler(), new Shapes(), $diagnostics);

        $context->error('name-collision', 'Two fields named `title`.', 'title', ApiRevision::Spring26);

        self::assertEquals(
            [new Diagnostic('name-collision', Severity::Error, CountingDeclaration::class, 'title', 'spring-26', 'Two fields named `title`.')],
            $diagnostics->all(),
        );
    }

    public function test_a_warning_without_field_nor_revision_concerns_the_whole_class(): void
    {
        $diagnostics = new Diagnostics();
        $context = new CheckContext(ApiRevision::class, CountingDeclaration::class, new Compiler(), new Shapes(), $diagnostics);

        $context->warning('bound-repeated', 'Replaced.');

        self::assertEquals(
            [new Diagnostic('bound-repeated', Severity::Warning, CountingDeclaration::class, null, null, 'Replaced.')],
            $diagnostics->all(),
        );
    }

    public function test_a_diagnostic_on_the_enum_names_the_enum(): void
    {
        $diagnostics = new Diagnostics();
        $context = new CheckContext(ApiRevision::class, null, new Compiler(), new Shapes(), $diagnostics);

        $context->error('order-missing-case', 'Missing.', revision: ApiRevision::Atlas);

        self::assertEquals(
            [new Diagnostic('order-missing-case', Severity::Error, ApiRevision::class, null, 'atlas', 'Missing.')],
            $diagnostics->all(),
        );
    }

    public function test_the_shape_is_the_one_of_the_checked_class(): void
    {
        $context = new CheckContext(ApiRevision::class, CountingDeclaration::class, new Compiler(), new Shapes(), new Diagnostics());

        $shape = $context->shape(DeclarationKind::Response, ApiRevision::Genesis);

        self::assertSame(CountingDeclaration::class, $shape->class);
        self::assertSame(['name'], $shape->names());
    }

    public function test_the_enum_has_no_shape(): void
    {
        $context = new CheckContext(ApiRevision::class, null, new Compiler(), new Shapes(), new Diagnostics());

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('The revision enum Test\\Fixtures\\ApiRevision has no shape: a check asks a shape only of a declaring class.');

        $context->shape(DeclarationKind::Response, ApiRevision::Genesis);
    }

    public function test_a_diagnostic_on_a_revision_of_another_enum_is_refused(): void
    {
        $context = new CheckContext(ApiRevision::class, CountingDeclaration::class, new Compiler(), new Shapes(), new Diagnostics());

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Test\\Fixtures\\Revisions\\IntRevision::First is not a revision of Test\\Fixtures\\ApiRevision.');

        $context->warning('code', 'message', revision: IntRevision::First);
    }

    public function test_the_context_reads_the_declarations_of_the_checked_class(): void
    {
        $converters = Converters::builtIn();
        $context = new CheckContext(ApiRevision::class, StoreOrderDeclaration::class, new Compiler(converters: $converters), new Shapes(), new Diagnostics());

        self::assertSame(ApiRevision::order(), $context->order());
        self::assertSame([DeclarationKind::RequestBody, DeclarationKind::RequestQuery], $context->kinds());
        self::assertSame(['status'], array_keys($context->descriptors(DeclarationKind::RequestQuery)));
        self::assertSame($converters, $context->converters());
    }

    public function test_the_enum_has_no_declaration(): void
    {
        $context = new CheckContext(ApiRevision::class, null, new Compiler(), new Shapes(), new Diagnostics());

        self::assertSame([], $context->kinds());

        $this->expectException(DefinitionException::class);
        $context->descriptors(DeclarationKind::Response);
    }

    public function test_a_revision_is_found_by_its_backed_value_as_a_string(): void
    {
        $context = new CheckContext(IntRevision::class, null, new Compiler(), new Shapes(), new Diagnostics());

        self::assertSame(IntRevision::cases()[1], $context->revision((string) IntRevision::cases()[1]->value));
        self::assertNull($context->revision('unknown'));
    }

    public function test_the_export_is_the_one_of_the_checked_class_from_the_shapes_of_the_run(): void
    {
        $shapes = new Shapes();
        $context = new CheckContext(ApiRevision::class, CountingDeclaration::class, new Compiler(), $shapes, new Diagnostics());

        self::assertSame(
            (new JsonSchemaExporter($shapes))->export(CountingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis),
            $context->export(DeclarationKind::Response, ApiRevision::Genesis),
        );
    }

    public function test_the_enum_has_no_export(): void
    {
        $context = new CheckContext(ApiRevision::class, null, new Compiler(), new Shapes(), new Diagnostics());

        $this->expectException(DefinitionException::class);
        $context->export(DeclarationKind::Response, ApiRevision::Genesis);
    }
}
