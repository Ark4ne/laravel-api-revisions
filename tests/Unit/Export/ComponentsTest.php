<?php

declare(strict_types=1);

namespace Test\Unit\Export;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Export\Components;
use Test\TestCase;

final class ComponentsTest extends TestCase
{
    public function test_a_reference_is_a_json_pointer_into_the_definitions(): void
    {
        self::assertSame('#/$defs/Address', Components::reference('Address'));
        self::assertSame('#/$defs/a~1b~0c', Components::reference('a/b~c'));
    }

    public function test_a_component_is_defined_once_in_the_order_of_first_use(): void
    {
        $components = new Components('response', 'atlas');

        $components->define('money', ['type' => 'object'], 'price');
        $components->define('Address', ['type' => 'object', 'required' => ['street']], 'to');
        $components->define('money', ['type' => 'object'], 'fee');

        self::assertTrue($components->has('money'));
        self::assertFalse($components->has('Zip'));
        self::assertSame(['money' => ['type' => 'object'], 'Address' => ['type' => 'object', 'required' => ['street']]], $components->all());
    }

    public function test_a_reserved_name_is_filled_by_its_nested_resource(): void
    {
        $components = new Components('response', 'atlas');

        $components->reserve('App.Customer');
        self::assertTrue($components->has('App.Customer'));
        $components->define('Address', [], 'customer.address');
        $components->fill('App.Customer', ['type' => 'object']);

        self::assertSame(['App.Customer' => ['type' => 'object'], 'Address' => []], $components->all());
    }

    public function test_another_body_under_a_defined_name_is_a_conflict(): void
    {
        $components = new Components('response', 'winter-25');
        $components->define('Address', ['type' => 'object'], 'to');

        $this->expectExceptionObject(DefinitionException::componentConflict('Address', 'from.address', 'response', 'winter-25'));

        $components->define('Address', ['type' => 'object', 'required' => ['street']], 'from.address');
    }

    public function test_a_component_named_as_a_nested_resource_is_a_conflict(): void
    {
        $components = new Components('response', 'atlas');
        $components->reserve('Address');

        $this->expectExceptionObject(DefinitionException::componentConflict('Address', 'to', 'response', 'atlas'));

        $components->define('Address', ['type' => 'object'], 'to');
    }
}
