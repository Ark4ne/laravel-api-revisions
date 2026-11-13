<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\Fields;
use Ark4ne\ApiRevisions\Compilation\Compiler;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\TestCase;

final class FieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CheckedDeclaration::reset();
        CheckedDeclaration::$describe = static fn(): array => [
            'title' => Schema::string(),
            'customer' => Schema::object([
                'name' => Schema::string()->was(ApiRevision::Genesis, name: 'label'),
                'address' => Schema::object(['city' => Schema::string()]),
            ]),
            'lines' => Schema::list(Schema::object(['sku' => Schema::string()])),
            'buyer' => Schema::resource(CustomerDeclaration::class),
            'late' => Schema::string()->since(ApiRevision::Atlas),
        ];
    }

    public function test_descriptors_are_walked_by_their_path_of_head_keys(): void
    {
        $paths = [];
        foreach (Fields::descriptors((new Compiler())->describe(CheckedDeclaration::class, DeclarationKind::Response)) as $path => $descriptor) {
            $paths[] = $path;
        }

        self::assertSame(['title', 'customer', 'customer.name', 'customer.address', 'customer.address.city', 'lines', 'lines.*', 'lines.*.sku', 'buyer', 'late'], $paths);
    }

    public function test_the_visible_fields_are_walked_by_their_path_of_head_keys(): void
    {
        $shape = (new Compiler())->compile(CheckedDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis);

        $fields = [];
        foreach (Fields::of($shape) as $path => $field) {
            $fields[$path] = $field->name;
        }

        self::assertSame(
            ['title' => 'title', 'customer' => 'customer', 'customer.name' => 'label', 'customer.address' => 'address', 'customer.address.city' => 'city', 'lines' => 'lines', 'lines.*' => '*', 'lines.*.sku' => 'sku', 'buyer' => 'buyer'],
            $fields,
        );
    }

    public function test_the_shapes_of_objects_are_walked_by_the_path_of_their_field(): void
    {
        $shape = (new Compiler())->compile(CheckedDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis);

        $paths = [];
        foreach (Fields::shapes($shape) as $path => $nested) {
            $paths[$path] = $nested->names();
        }

        self::assertSame(
            ['' => ['title', 'customer', 'lines', 'buyer'], 'customer' => ['label', 'address'], 'customer.address' => ['city'], 'lines.*' => ['sku']],
            $paths,
        );
    }
}
