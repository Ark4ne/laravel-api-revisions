<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Resources;

use Ark4ne\ApiRevisions\Laravel\Resources\EloquentSourceReader;
use Illuminate\Database\Eloquent\Model;
use Test\Fixtures\Eloquent\Customer;
use Test\Fixtures\Eloquent\Order;
use Test\Fixtures\MagicSubject;
use Test\TestCase;

final class EloquentSourceReaderTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    public function test_a_model_holds_its_attributes_and_its_loaded_relations(): void
    {
        $reader = new EloquentSourceReader();
        $order = self::order();

        self::assertTrue($reader->has($order, ['total_cents']));
        self::assertTrue($reader->has($order, ['customer', 'name']));
        self::assertFalse($reader->has($order, ['customer', 'email']));
        self::assertFalse($reader->has($order, ['exists']));
        self::assertFalse($reader->has(self::order(customer: false), ['customer']));
        self::assertTrue($reader->has(['order' => $order], ['order', 'title']));
        self::assertFalse($reader->has(['order' => $order], ['missing']));
    }

    public function test_a_relation_is_loaded_segment_by_segment_without_loading_it(): void
    {
        $reader = new EloquentSourceReader();
        $order = self::order();
        $order->customer?->setRelation('orders', null);

        self::assertTrue($reader->loaded($order, ['customer']));
        self::assertTrue($reader->loaded($order, ['customer', 'orders']));
        self::assertFalse($reader->loaded($order, ['customer', 'orders', 'lines']));
        self::assertFalse($reader->loaded($order, ['title']));
        self::assertFalse($reader->loaded(self::order(customer: false), ['customer']));
        self::assertTrue($reader->loaded(['order' => $order], ['order', 'customer']));
        self::assertFalse($reader->loaded(['customer' => $order], ['missing']));
    }

    public function test_a_peeked_key_is_a_cast_attribute_or_a_loaded_relation_and_never_loads(): void
    {
        $reader = new EloquentSourceReader();
        Model::preventLazyLoading();
        $order = self::order(customer: false);
        $order->exists = true;

        self::assertSame(1250, $reader->peek($order, 'total_cents'));
        self::assertNull($reader->peek($order, 'customer'));
        self::assertNull($reader->peek($order, 'missing'));
        self::assertInstanceOf(Customer::class, $reader->peek(self::order(), 'customer'));
        self::assertSame('public', $reader->peek(new MagicSubject(), 'plain'));
    }

    public function test_a_key_is_read_as_the_model_reads_it(): void
    {
        $reader = new EloquentSourceReader();

        self::assertSame('Ada', $reader->read(self::order(), ['customer', 'name']));
        self::assertSame(1250, $reader->read(self::order(), ['total_cents']));
        self::assertSame('Ada', $reader->read(['order' => self::order()], ['order', 'customer', 'name']));
    }

    private static function order(bool $customer = true): Order
    {
        $order = new Order(['title' => 'Lamp', 'total_cents' => '1250']);
        if ($customer) {
            $order->setRelation('customer', new Customer(['name' => 'Ada']));
        }

        return $order;
    }
}
