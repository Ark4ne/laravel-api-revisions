<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResourceCollection;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Eloquent\Customer;
use Test\Fixtures\Eloquent\Order;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Resources\CountedResource;
use Test\Fixtures\Resources\NativeResource;
use Test\Fixtures\Resources\NoteResource;
use Test\Fixtures\Resources\OrderResource;
use Test\Fixtures\Resources\ReachingResource;

/**
 * A collection of a `RevisionedResource` runs `toArray()` once per render on a template instance and projects every
 * item through one plan.
 */
final class RevisionedResourceCollectionTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->application()['config']->set('revisions.enum', ApiRevision::class);
        CountedResource::$calls = 0;
        CountedResource::$perSubject = false;
    }

    public function test_the_collection_of_a_revisioned_resource_is_a_revisioned_collection(): void
    {
        self::assertInstanceOf(RevisionedResourceCollection::class, OrderResource::collection([]));
        self::assertSame([], $this->render(OrderResource::collection([]), ApiRevision::Atlas));
    }

    public function test_a_collection_runs_to_array_once_per_render(): void
    {
        $orders = [self::order('Lamp'), self::order('Desk'), self::order('Vase')];
        // the shape compiled first
        $this->render(CountedResource::collection([self::order('Warm')]), ApiRevision::Atlas);
        CountedResource::$calls = 0;

        $rendered = $this->render(CountedResource::collection($orders), ApiRevision::Atlas);

        self::assertSame(1, CountedResource::$calls);
        self::assertSame(['LAMP', 'DESK', 'VASE'], array_column($rendered, 'label'));
        $this->render(CountedResource::collection($orders), ApiRevision::Atlas);
        self::assertSame(2, CountedResource::$calls);
    }

    public function test_each_render_sees_the_request_it_serves(): void
    {
        $orders = [self::order('Lamp'), self::order('Desk')];

        $first = $this->render(CountedResource::collection($orders), ApiRevision::Atlas, Request::create('/orders?asked=first'));
        $second = $this->render(CountedResource::collection($orders), ApiRevision::Atlas, Request::create('/orders?asked=second'));

        self::assertSame(['first', 'first'], array_column($first, 'asked'));
        self::assertSame(['second', 'second'], array_column($second, 'asked'));
    }

    /**
     * @return iterable<string, array{Revision}>
     */
    public static function revisions(): iterable
    {
        foreach (ApiRevision::cases() as $revision) {
            yield $revision->name => [$revision];
        }
    }

    #[DataProvider('revisions')]
    public function test_a_collection_renders_what_each_item_renders(Revision $revision): void
    {
        // fresh models on each side: a render may load a relation (`fullName()` reads `customer`)
        $orders = static fn(): array => [self::order('Lamp', customer: true), self::order('Desk'), self::order('Vase', notes: 'fragile', customer: true)];

        $expected = array_map(fn(Order $order): array => $this->render(new OrderResource($order), $revision), $orders());

        self::assertSame($expected, $this->render(OrderResource::collection($orders()), $revision));
    }

    public function test_the_keys_are_preserved_when_asked(): void
    {
        $orders = new EloquentCollection(['a' => self::order('Lamp'), 'b' => self::order('Desk')]);

        $rendered = $this->render(OrderResource::collection($orders)->preserveKeys(), ApiRevision::Atlas);

        self::assertSame(['a', 'b'], array_keys($rendered));
    }

    /**
     * @return iterable<string, array{class-string<JsonResource>, list<array{string, ?string, ?bool}>}>
     */
    public static function perSubject(): iterable
    {
        // plain values and native conditions
        yield 'native conditions' => [NativeResource::class, [['Lamp', null, true], ['Desk', null, false]]];
        yield 'a plain value' => [ReachingResource::class, [['Lamp', null, null], ['Desk', null, null]]];
        // a key the template's declaration lacks: a structure decided in plain PHP
        yield 'a key the template lacks' => [NoteResource::class, [['Lamp', null, null], ['Desk', 'fragile', null]]];
    }

    /**
     * @param class-string<JsonResource> $resource
     * @param list<array{string, ?string, ?bool}> $orders title, notes, visible
     */
    #[DataProvider('perSubject')]
    public function test_a_declaration_the_plan_cannot_hold_keeps_the_per_subject_path(string $resource, array $orders): void
    {
        $build = static fn(): array => array_map(static fn(array $order): Order => self::order($order[0], $order[1], visible: $order[2]), $orders);

        $expected = array_map(fn(Order $order): array => $this->render(new $resource($order), ApiRevision::Atlas), $build());

        self::assertSame($expected, $this->render($resource::collection($build()), ApiRevision::Atlas));
    }

    public function test_a_collection_mixing_resources_resolves_each_item(): void
    {
        $orders = [new OrderResource(self::order('Lamp')), new CountedResource(self::order('Desk'))];

        $rendered = $this->render(OrderResource::collection($orders), ApiRevision::Atlas);

        // each item rendered by its own class: only the second has a label
        self::assertSame(['Lamp', 'Desk'], array_column($rendered, 'title'));
        self::assertSame(['DESK'], array_column($rendered, 'label'));
    }

    public function test_a_class_opting_out_runs_to_array_per_subject(): void
    {
        $this->render(CountedResource::collection([self::order('Warm')]), ApiRevision::Atlas);
        CountedResource::$perSubject = true;
        CountedResource::$calls = 0;

        $rendered = $this->render(CountedResource::collection([self::order('Lamp'), self::order('Desk')]), ApiRevision::Atlas);

        self::assertSame(2, CountedResource::$calls);
        self::assertSame(['LAMP', 'DESK'], array_column($rendered, 'label'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function render(JsonResource $resource, Revision $revision, ?Request $request = null): array
    {
        return ApiRevision::using($revision, static fn(): array => $resource->resolve($request));
    }

    private static function order(string $title, ?string $notes = null, bool $customer = false, ?bool $visible = null): Order
    {
        $order = new Order([
            'title' => $title,
            'status' => OrderStatus::Paid,
            'total_cents' => 1250,
            'placed_at' => '2026-03-01 10:30:00',
            'tags' => ['gift'],
            'notes' => $notes,
            'visible' => $visible,
        ]);
        if ($customer) {
            $order->setRelation('customer', new Customer(['name' => 'Ada Lovelace']));
        }

        return $order;
    }
}
