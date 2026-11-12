<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Compilation\Probe;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\EagerSchema;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Router;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Eloquent\Customer;
use Test\Fixtures\Eloquent\Order;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Resources\BrokenResource;
use Test\Fixtures\Resources\CustomerResource;
use Test\Fixtures\Resources\NativeResource;
use Test\Fixtures\Resources\OrderResource;
use Test\Fixtures\Resources\ReachingResource;
use Test\Fixtures\Resources\WrapperResource;

final class RevisionedResourceTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->application()['config']->set('revisions.enum', ApiRevision::class);
    }

    /**
     * @return iterable<string, array{Revision, array<string, mixed>}>
     */
    public static function referenceTable(): iterable
    {
        yield 'Atlas (head)' => [ApiRevision::Atlas, [
            'title' => 'Lamp',
            'status' => 'paid',
            'total' => 1250,
            'placed_at' => '2026-03-01T10:30:00+00:00',
            'customer' => ['name' => 'Ada Lovelace'],
            'tags' => ['gift'],
            'notes' => null,
        ]];
        yield 'Spring26' => [ApiRevision::Spring26, [
            'title' => 'Lamp',
            'status' => 'paid',
            'total' => 1250,
            'placed_at' => '2026-03-01T10:30:00+00:00',
            'customer' => ['name' => 'Ada Lovelace'],
            'name' => 'Ada Lovelace',
        ]];
        yield 'Winter25' => [ApiRevision::Winter25, [
            'title' => 'Lamp',
            'state' => 'paid',
            'total' => '12.50',
            'placed_at' => '2026-03-01',
            'customer' => ['full_name' => 'Ada Lovelace'],
            'name' => 'Ada Lovelace',
        ]];
        yield 'Genesis' => [ApiRevision::Genesis, [
            'title' => 'Lamp',
            'state' => 'paid',
            'total' => 12.5,
            'placed_at' => '2026-03-01',
            'customer' => ['full_name' => 'Ada Lovelace'],
            'name' => 'Ada Lovelace',
        ]];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('referenceTable')]
    public function test_order_resource_renders_each_revision_as_in_the_reference_table(Revision $revision, array $expected): void
    {
        self::assertSame($expected, $this->render(new OrderResource(self::order()), $revision));
    }

    public function test_a_resource_collection_is_projected_item_by_item(): void
    {
        $this->route(static fn(): mixed => OrderResource::collection(new EloquentCollection([self::order(), self::order('Desk')])));

        $this->getJson('/orders', ['Api-Revision' => 'winter-25'])
            ->assertOk()
            ->assertJsonPath('data.0.state', 'paid')
            ->assertJsonPath('data.0.total', '12.50')
            ->assertJsonPath('data.1.title', 'Desk')
            ->assertJsonMissingPath('data.1.status');
    }

    public function test_a_paginated_collection_keeps_its_links_and_meta(): void
    {
        $this->route(static fn(): mixed => OrderResource::collection(new LengthAwarePaginator([self::order()], 3, 1)));

        $this->getJson('/orders', ['Api-Revision' => 'genesis'])
            ->assertOk()
            ->assertJsonPath('data.0.total', 12.5)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta']);
    }

    public function test_a_resource_response_keeps_its_wrapping_and_additional_data(): void
    {
        $this->route(static fn(): mixed => (new OrderResource(self::order()))->additional(['meta' => ['source' => 'test']]));

        $this->getJson('/orders', ['Api-Revision' => 'spring-26'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Lamp')
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('meta.source', 'test');
    }

    public function test_closures_in_to_array_reach_the_model_three_ways(): void
    {
        $order = self::order();

        self::assertSame([
            'through_resource' => 'Lamp',
            'delegated' => 'Lamp',
            'subject' => 'Lamp',
            'title' => 'peeked Lamp',
            'customer' => Customer::class,
            'asked' => null,
        ], $this->render(new ReachingResource($order), ApiRevision::Atlas));
    }

    public function test_the_request_given_to_resolve_reaches_to_array(): void
    {
        $resource = new ReachingResource(self::order());

        self::assertSame('yes', $resource->resolve(Request::create('/orders?asked=yes'))['asked']);
    }

    public function test_the_shape_never_depends_on_the_request_served(): void
    {
        $this->application()->instance('request', Request::create('/orders?flag=1'));

        $names = $this->application()->make(Shapes::class)->for(ReachingResource::class, DeclarationKind::Response, ApiRevision::Atlas)->names();

        self::assertNotContains('flag', $names);
        self::assertSame('on', $this->render(new ReachingResource(self::order()), ApiRevision::Atlas)['flag']);
    }

    public function test_a_plain_value_failing_on_the_empty_subject_of_the_compilation_names_the_resource(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(BrokenResource::class . '::toArray() failed while compiling on an empty subject (Call to a member function format() on null): a plain value is evaluated with every read giving null, make it null-safe (?->) or declare a descriptor.');

        $this->application()->make(Shapes::class)->for(BrokenResource::class, DeclarationKind::Response, ApiRevision::Atlas);
    }

    public function test_the_value_of_a_source_closure_never_loads_a_relation(): void
    {
        $order = self::order(customer: false);
        Order::preventLazyLoading();
        $order->exists = true;

        try {
            $rendered = $this->render(new ReachingResource($order), ApiRevision::Atlas);
        } finally {
            Order::preventLazyLoading(false);
        }

        self::assertSame('null', $rendered['customer']);
    }

    public function test_when_loaded_omits_a_relation_not_loaded(): void
    {
        $order = self::order(customer: false);
        Order::preventLazyLoading();
        $order->exists = true;

        try {
            $rendered = $this->render(new OrderResource($order), ApiRevision::Atlas);
        } finally {
            Order::preventLazyLoading(false);
        }

        self::assertArrayNotHasKey('customer', $rendered);
        self::assertSame('Lamp', $rendered['title']);
    }

    public function test_a_nested_resource_projects_its_loaded_relations_at_the_same_revision(): void
    {
        $order = self::order();
        $customer = $order->customer;
        self::assertInstanceOf(Customer::class, $customer);
        $customer->setRelation('orders', new EloquentCollection([self::order('Desk', customer: false)]));

        $rendered = $this->render(new OrderResource($order), ApiRevision::Spring26);

        self::assertSame([
            'name' => 'Ada Lovelace',
            'orders' => [[
                'title' => 'Desk',
                'status' => 'paid',
                'total' => 1250,
                'placed_at' => '2026-03-01T10:30:00+00:00',
                'name' => 'anonymous',
            ]],
        ], $rendered['customer']);
    }

    public function test_an_eager_value_is_rendered_as_is(): void
    {
        $rendered = $this->render(new NativeResource(self::order()), ApiRevision::Genesis);

        self::assertSame('Lamp', $rendered['label']);
        self::assertArrayHasKey('notes', $rendered);
        self::assertNull($rendered['notes']);
    }

    public function test_an_eager_value_is_a_nullable_any_field_of_every_revision(): void
    {
        $shapes = $this->application()->make(Shapes::class);

        foreach (ApiRevision::cases() as $revision) {
            $field = $shapes->for(NativeResource::class, DeclarationKind::Response, $revision)->field('label');
            self::assertNotNull($field);
            self::assertSame(Kind::Any, $field->type->kind);
            self::assertTrue($field->nullable);
            self::assertTrue($field->conditional);
        }
        self::assertInstanceOf(EagerSchema::class, NativeResource::describeRevisioned(DeclarationKind::Response, new Probe(NativeResource::class))['label']);
    }

    public function test_native_conditions_are_honoured_at_run_time_and_kept_out_of_the_shape(): void
    {
        $visible = $this->render(new NativeResource(self::order()), ApiRevision::Atlas);
        $hidden = $this->render(new NativeResource(self::order(customer: false, visible: false)), ApiRevision::Atlas);

        self::assertSame('shown', $visible['secret']);
        self::assertSame('yes', $visible['merged']);
        self::assertSame(['name' => 'Ada Lovelace'], $visible['buyer']);
        self::assertInstanceOf(CustomerResource::class, $visible['buyer_resource']);
        self::assertSame(['kept' => 1], $visible['nested']);
        self::assertArrayNotHasKey('regular', $visible);
        self::assertArrayNotHasKey('secret', $hidden);
        self::assertArrayNotHasKey('merged', $hidden);
        self::assertArrayNotHasKey('buyer', $hidden);
        self::assertArrayNotHasKey('buyer_resource', $hidden);
        self::assertArrayNotHasKey('shown_only', $hidden);
        self::assertSame('regular', $hidden['regular']);
        self::assertSame(
            ['title', 'label', 'notes', 'labels', 'regular', 'shown_only', 'nested', 'any'],
            $this->application()->make(Shapes::class)->for(NativeResource::class, DeclarationKind::Response, ApiRevision::Atlas)->names(),
        );
    }

    public function test_a_nested_resource_omits_the_native_conditions_missing_on_its_subject(): void
    {
        $visible = $this->render(new WrapperResource(['order' => self::order()]), ApiRevision::Atlas);
        $hidden = $this->render(new WrapperResource(['order' => self::order(visible: false)]), ApiRevision::Atlas);

        self::assertSame([
            'order' => ['title' => 'Lamp', 'label' => 'Lamp', 'notes' => null, 'labels' => ['gift'], 'shown_only' => 'x', 'nested' => ['kept' => 1], 'any' => ['a' => 1]],
        ], $visible);
        self::assertSame([
            'order' => ['title' => 'Lamp', 'label' => 'Lamp', 'notes' => null, 'labels' => ['gift'], 'regular' => 'regular', 'nested' => ['kept' => 1], 'any' => ['a' => 1]],
        ], $hidden);
    }

    public function test_the_rendering_keeps_the_order_of_to_array(): void
    {
        self::assertSame(
            ['title', 'label', 'notes', 'labels', 'secret', 'merged', 'buyer', 'buyer_resource', 'shown_only', 'nested', 'old', 'any'],
            array_keys($this->render(new NativeResource(self::order()), ApiRevision::Genesis)),
        );
    }

    public function test_arrayable_any_values_are_rendered_as_arrays(): void
    {
        $rendered = $this->render(new NativeResource(self::order()), ApiRevision::Atlas);

        self::assertSame(['gift'], $rendered['labels']);
        self::assertSame(['a' => 1], $rendered['any']);
    }

    public function test_lenient_value_errors_are_reported(): void
    {
        $this->application()['config']->set('revisions.strict_values', false);
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects(self::once())->method('report')->with(self::isInstanceOf(ValueException::class));
        $this->application()->instance(ExceptionHandler::class, $handler);
        $order = self::order();
        $order->setRawAttributes([...$order->getAttributes(), 'title' => ['not', 'a', 'string']]);

        $rendered = $this->render(OrderResource::collection([$order, $order]), ApiRevision::Atlas);

        self::assertArrayHasKey(0, $rendered);
        self::assertIsArray($rendered[0]);
        self::assertNull($rendered[0]['title']);
    }

    public function test_the_resource_declares_a_response_compiled_without_reading_its_model(): void
    {
        self::assertSame([DeclarationKind::Response], OrderResource::declarationKinds());
        self::assertSame(
            ['title', 'state', 'total', 'placed_at', 'customer', 'name'],
            $this->application()->make(Shapes::class)->for(OrderResource::class, DeclarationKind::Response, ApiRevision::Genesis)->names(),
        );
        self::assertSame(['name', 'orders'], $this->application()->make(Shapes::class)->for(CustomerResource::class, DeclarationKind::Response, ApiRevision::Atlas)->names());
    }

    private static function order(string $title = 'Lamp', bool $customer = true, bool $visible = true): Order
    {
        $order = new Order([
            'title' => $title,
            'status' => OrderStatus::Paid,
            'total_cents' => 1250,
            'placed_at' => '2026-03-01 10:30:00',
            'tags' => ['gift'],
            'notes' => null,
            'visible' => $visible,
        ]);
        if ($customer) {
            $order->setRelation('customer', new Customer(['name' => 'Ada Lovelace']));
        }

        return $order;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function render(\Illuminate\Http\Resources\Json\JsonResource $resource, Revision $revision): array
    {
        return ApiRevision::using($revision, static fn(): array => $resource->resolve());
    }

    private function route(Closure $action): void
    {
        $this->application()->make(Router::class)->get('/orders', $action)->middleware('revision');
    }
}
