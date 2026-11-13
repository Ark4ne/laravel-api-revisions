<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Support\Diagnostic;
use Ark4ne\ApiRevisions\Support\Diagnostics;
use Ark4ne\ApiRevisions\Support\Severity;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\Fixtures\Revisions\IntRevision;
use Test\TestCase;

final class CheckContextTest extends TestCase
{
    public function test_an_error_names_the_checked_class_its_field_and_revision(): void
    {
        $diagnostics = new Diagnostics();
        $context = new CheckContext(ApiRevision::class, CountingDeclaration::class, new Shapes(), $diagnostics);

        $context->error('name-collision', 'Two fields named `title`.', 'title', ApiRevision::Spring26);

        self::assertEquals(
            [new Diagnostic('name-collision', Severity::Error, CountingDeclaration::class, 'title', 'spring-26', 'Two fields named `title`.')],
            $diagnostics->all(),
        );
    }

    public function test_a_warning_without_field_nor_revision_concerns_the_whole_class(): void
    {
        $diagnostics = new Diagnostics();
        $context = new CheckContext(ApiRevision::class, CountingDeclaration::class, new Shapes(), $diagnostics);

        $context->warning('bound-repeated', 'Replaced.');

        self::assertEquals(
            [new Diagnostic('bound-repeated', Severity::Warning, CountingDeclaration::class, null, null, 'Replaced.')],
            $diagnostics->all(),
        );
    }

    public function test_a_diagnostic_on_the_enum_names_the_enum(): void
    {
        $diagnostics = new Diagnostics();
        $context = new CheckContext(ApiRevision::class, null, new Shapes(), $diagnostics);

        $context->error('order-missing-case', 'Missing.', revision: ApiRevision::Atlas);

        self::assertEquals(
            [new Diagnostic('order-missing-case', Severity::Error, ApiRevision::class, null, 'atlas', 'Missing.')],
            $diagnostics->all(),
        );
    }

    public function test_the_shape_is_the_one_of_the_checked_class(): void
    {
        $context = new CheckContext(ApiRevision::class, CountingDeclaration::class, new Shapes(), new Diagnostics());

        $shape = $context->shape(DeclarationKind::Response, ApiRevision::Genesis);

        self::assertSame(CountingDeclaration::class, $shape->class);
        self::assertSame(['name'], $shape->names());
    }

    public function test_the_enum_has_no_shape(): void
    {
        $context = new CheckContext(ApiRevision::class, null, new Shapes(), new Diagnostics());

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('The revision enum Test\\Fixtures\\ApiRevision has no shape: a check asks a shape only of a declaring class.');

        $context->shape(DeclarationKind::Response, ApiRevision::Genesis);
    }

    public function test_a_diagnostic_on_a_revision_of_another_enum_is_refused(): void
    {
        $context = new CheckContext(ApiRevision::class, CountingDeclaration::class, new Shapes(), new Diagnostics());

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Test\\Fixtures\\Revisions\\IntRevision::First is not a revision of Test\\Fixtures\\ApiRevision.');

        $context->warning('code', 'message', revision: IntRevision::First);
    }
}
