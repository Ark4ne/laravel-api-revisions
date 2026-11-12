<?php

declare(strict_types=1);

namespace Test\Unit\Compilation;

use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Compilation\ShapeSelector;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Configuration\MissingDefault;
use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Conversion\StepKind;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use Ark4ne\ApiRevisions\Support\Undefined;
use stdClass;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\EagerDeclaration;
use Test\Fixtures\Declarations\InvalidDeclaration;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Declarations\StoreOrderDeclaration;
use Test\Fixtures\Declarations\ThrowingDeclaration;
use Test\Fixtures\MoneySchema;
use Test\TestCase;

final class CompilerTest extends TestCase
{
    public function test_compiling_a_class_runs_no_source(): void
    {
        $shape = (new Compiler())->compile(ThrowingDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis);

        self::assertSame(['total'], $shape->names());
        self::assertSame(['title', 'state', 'total', 'placed_at', 'delivery', 'name'], (new Compiler())
            ->compile(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis)->names());
    }

    public function test_compiling_gives_the_shape_of_the_revision(): void
    {
        $compiled = (new Compiler())->compile(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25);
        $selected = ShapeSelector::at(
            array_map(
                static fn(Descriptor $descriptor): Descriptor => $descriptor->configured(new Configuration()),
                OrderDeclaration::describeRevisioned(DeclarationKind::Response, null),
            ),
            ApiRevision::Winter25,
            Direction::Down,
        );

        self::assertEquals($selected->of(OrderDeclaration::class, DeclarationKind::Response), $compiled);
        self::assertSame(OrderDeclaration::class, $compiled->class);
        self::assertSame(DeclarationKind::Response, $compiled->kind);
        self::assertSame(Direction::Down, $compiled->direction);
        self::assertSame(ApiRevision::Winter25, $compiled->revision);
        self::assertSame(Kind::String, $compiled->fields['total']->type->kind);
        self::assertSame(StepKind::Explicit, $compiled->fields['total']->chain->steps[0]->kind);
    }

    public function test_compiling_resolves_the_configured_date_format(): void
    {
        $compiler = new Compiler(new Configuration(dateFormat: 'd/m/Y', dateTimeFormat: 'Y-m-d H:i'));

        $head = $compiler->compile(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas);
        $old = $compiler->compile(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25);

        self::assertSame('Y-m-d H:i', $head->fields['placed_at']->type->format);
        self::assertSame('d/m/Y', $old->fields['placed_at']->type->format);
        self::assertSame('d/m/Y', $head->fields['delivery']->children?->fields['day']->type->format);
        self::assertSame('d/m/Y', $head->fields['delivery']->type->children['day']->format);
    }

    public function test_a_request_compiles_its_body_and_its_query_up(): void
    {
        $compiler = new Compiler();

        $body = $compiler->compile(StoreOrderDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);
        $query = $compiler->compile(StoreOrderDeclaration::class, DeclarationKind::RequestQuery, ApiRevision::Genesis);

        self::assertSame(['title', 'total'], $body->names());
        self::assertSame(['surname'], array_keys($body->added));
        self::assertSame(Direction::Up, $body->direction);
        self::assertSame(DeclarationKind::RequestBody, $body->kind);
        self::assertSame(['state'], $query->names());
        self::assertSame(DeclarationKind::RequestQuery, $query->kind);
    }

    public function test_the_configuration_reaches_the_defaults(): void
    {
        $compiler = new Compiler(new Configuration(missingDefault: MissingDefault::Null));

        $body = $compiler->compile(StoreOrderDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);

        self::assertNull($body->added['surname']->default);
        self::assertTrue(Undefined::is((new Compiler())->compile(StoreOrderDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis)->added['surname']->default));
    }

    public function test_the_registered_custom_types_convert_by_default(): void
    {
        TypeRegistry::register('money', MoneySchema::class);

        $body = (new Compiler())->compile(StoreOrderDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);
        $without = (new Compiler(converters: new Converters()))->compile(StoreOrderDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Genesis);

        self::assertSame(StepKind::Registered, $body->fields['total']->chain->steps[0]->kind);
        self::assertSame(StepKind::Missing, $without->fields['total']->chain->steps[0]->kind);
    }

    public function test_a_declaration_reading_its_subject_fails(): void
    {
        $this->expectExceptionObject(DefinitionException::subjectReadAtCompilation(EagerDeclaration::class, '->title'));

        (new Compiler())->compile(EagerDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas);
    }

    public function test_a_class_that_is_not_revisioned_is_refused(): void
    {
        $this->expectExceptionObject(DefinitionException::notRevisioned(stdClass::class));

        (new Compiler())->compile(stdClass::class, DeclarationKind::Response, ApiRevision::Atlas);
    }

    public function test_a_kind_the_class_does_not_declare_is_refused(): void
    {
        $this->expectExceptionObject(DefinitionException::undeclaredKind(OrderDeclaration::class, 'request-body'));

        (new Compiler())->compile(OrderDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Atlas);
    }

    public function test_a_plain_value_in_a_declaration_is_refused(): void
    {
        $this->expectExceptionObject(DefinitionException::invalidDeclaration(InvalidDeclaration::class, 'title', 'string'));

        (new Compiler())->compile(InvalidDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas);
    }

    public function test_an_integer_key_in_a_declaration_is_refused(): void
    {
        $this->expectExceptionObject(DefinitionException::invalidDeclaration(InvalidDeclaration::class, 0, 'Ark4ne\ApiRevisions\Schema\StringSchema'));

        (new Compiler())->compile(InvalidDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Atlas);
    }
}
