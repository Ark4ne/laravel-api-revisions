<?php

declare(strict_types=1);

namespace Test\Unit\Compilation;

use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Conversion\ConversionChain;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Support\Undefined;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\TestCase;

final class ShapeTest extends TestCase
{
    public function test_names_are_the_names_at_the_revision_in_head_order(): void
    {
        $shape = new Shape(ApiRevision::Winter25, Direction::Down, [
            'title' => self::field('title', 'title'),
            'status' => self::field('status', 'state'),
        ]);

        self::assertSame(['title', 'state'], $shape->names());
    }

    public function test_an_empty_shape_has_no_name(): void
    {
        self::assertSame([], (new Shape(ApiRevision::Atlas, Direction::Up, []))->names());
    }

    public function test_a_field_is_found_by_its_head_key_only(): void
    {
        $status = self::field('status', 'state');
        $shape = new Shape(ApiRevision::Winter25, Direction::Down, ['status' => $status]);

        self::assertSame($status, $shape->field('status'));
        self::assertNull($shape->field('state'));
    }

    public function test_added_fields_are_not_visible(): void
    {
        $shape = new Shape(ApiRevision::Spring26, Direction::Up, [], ['tags' => self::field('tags', 'tags')]);

        self::assertNull($shape->field('tags'));
        self::assertSame([], $shape->names());
        self::assertSame([], $shape->collisions);
    }

    private static function field(string $headKey, string $name): FieldDefinition
    {
        return new FieldDefinition(
            $headKey,
            ApiRevision::Winter25,
            $name,
            TypeDefinition::of(Kind::String),
            false,
            false,
            false,
            false,
            new Undefined(),
            Lifetime::always(),
            null,
            [],
            ConversionChain::empty(Direction::Down),
        );
    }

    public function test_a_shape_names_the_declaration_it_was_compiled_from(): void
    {
        $shape = new Shape(ApiRevision::Winter25, Direction::Down, ['status' => self::field('status', 'state')]);

        $named = $shape->of(OrderDeclaration::class, DeclarationKind::Response);

        self::assertNull($shape->class);
        self::assertSame(OrderDeclaration::class, $named->class);
        self::assertSame(DeclarationKind::Response, $named->kind);
        self::assertEquals(
            new Shape(ApiRevision::Winter25, Direction::Down, $shape->fields, [], [], OrderDeclaration::class, DeclarationKind::Response),
            $named,
        );
    }
}
