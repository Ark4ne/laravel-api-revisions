<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\HeaderResolver;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\PathResolver;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\QueryResolver;
use Ark4ne\ApiRevisions\Testing\InteractsWithRevisions;
use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Eloquent\Customer;
use Test\Fixtures\Eloquent\Order;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Resolvers\AccountPinnedResolver;
use Test\Fixtures\Resources\NativeResource;
use Test\Fixtures\Resources\NoteResource;
use Test\Fixtures\Resources\OrderResource;

final class TestingHelpersTest extends FeatureTestCase
{
    use InteractsWithRevisions;

    private const array WINTER = [
        'title' => 'Lamp',
        'state' => 'paid',
        'total' => '12.50',
        'placed_at' => '2026-03-01',
        'name' => 'Ada Lovelace',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->application()['config']->set('revisions.enum', ApiRevision::class);
    }

    public function test_with_revision_sends_the_configured_header(): void
    {
        $this->resolvers([HeaderResolver::class => ['name' => 'X-Version']]);
        $this->route('/echo', static fn(Request $request): array => ['header' => $request->header('X-Version'), 'revision' => ApiRevision::current()->value]);

        $this->withRevision(ApiRevision::Winter25)->getJson('/echo')->assertExactJson(['header' => 'winter-25', 'revision' => 'winter-25']);
    }

    public function test_with_revision_fills_the_route_parameter_in_path_mode(): void
    {
        $this->resolvers([PathResolver::class => ['parameter' => 'version']]);
        $router = $this->application()->make(Router::class);
        $router->revisioned(static function (Router $router): void {
            $router->get('/orders', static fn(UrlGenerator $url): array => ['revision' => ApiRevision::current()->value])->name('orders.index');
        });
        $router->getRoutes()->refreshNameLookups();

        $this->withRevision(ApiRevision::Spring26);

        $url = $this->application()->make(UrlGenerator::class)->route('orders.index');
        self::assertStringEndsWith('/spring-26/orders', $url);
        $this->getJson($url)->assertExactJson(['revision' => 'spring-26']);
    }

    public function test_with_revision_adds_the_query_parameter_in_query_mode(): void
    {
        $this->resolvers([AccountPinnedResolver::class => [], QueryResolver::class => ['name' => 'v']]);
        $this->route('/echo', static fn(Request $request): array => ['query' => $request->query(), 'revision' => ApiRevision::current()->value]);

        $this->withRevision(ApiRevision::Genesis)->getJson('/echo?page=2')->assertExactJson(['query' => ['page' => '2', 'v' => 'genesis'], 'revision' => 'genesis']);
    }

    public function test_with_revision_needs_a_resolver_it_can_feed(): void
    {
        $this->resolvers([AccountPinnedResolver::class => []]);

        $this->expectExceptionObject(new LogicException('withRevision() sends a revision through a HeaderResolver, a PathResolver or a QueryResolver: none is in revisions.resolvers.'));

        $this->withRevision(ApiRevision::Genesis);
    }

    public function test_a_response_matches_the_schema_of_the_revision_of_its_header(): void
    {
        $this->route('/orders', static fn(): mixed => new OrderResource(self::order()));
        $this->route('/orders/all', static fn(): mixed => OrderResource::collection(new EloquentCollection([self::order(), self::order()])));

        $this->withRevision(ApiRevision::Winter25)->getJson('/orders')->assertMatchesRevisionSchema(OrderResource::class);
        $this->withRevision(ApiRevision::Atlas)->getJson('/orders/all')->assertMatchesRevisionSchema(OrderResource::class);
    }

    public function test_assert_matches_revision_schema_fails_on_an_extra_field(): void
    {
        $this->route('/orders', static fn(): JsonResponse => new JsonResponse(['data' => [...self::WINTER, 'leaked' => true]]));

        $this->expectFailure(
            "The response does not match the schema of Test\\Fixtures\\Resources\\OrderResource at winter-25:\n",
            fn() => $this->getJson('/orders')->assertMatchesRevisionSchema(OrderResource::class, ApiRevision::Winter25),
        );
    }

    public function test_an_unwrapped_body_is_validated_whole(): void
    {
        $this->route('/orders', static fn(): JsonResponse => new JsonResponse(self::WINTER));

        $this->getJson('/orders')->assertMatchesRevisionSchema(OrderResource::class, ApiRevision::Winter25);
        $this->expectFailure(
            "The response does not match the schema of Test\\Fixtures\\Resources\\OrderResource at atlas:\n",
            fn() => $this->getJson('/orders')->assertMatchesRevisionSchema(OrderResource::class, ApiRevision::Atlas),
        );
    }

    public function test_a_response_without_revision_header_needs_the_revision(): void
    {
        $this->route('/orders', static fn(): JsonResponse => new JsonResponse(self::WINTER), middleware: false);

        $this->expectFailure(
            'The response has no or an unknown Api-Revision header: pass the revision to assertMatchesRevisionSchema().',
            fn() => $this->getJson('/orders')->assertMatchesRevisionSchema(OrderResource::class),
        );
    }

    public function test_a_response_with_an_unknown_revision_header_needs_the_revision(): void
    {
        $this->route('/orders', static fn(): JsonResponse => new JsonResponse(self::WINTER, headers: ['Api-Revision' => 'unknown']), middleware: false);

        $this->expectFailure(
            'The response has no or an unknown Api-Revision header: pass the revision to assertMatchesRevisionSchema().',
            fn() => $this->getJson('/orders')->assertMatchesRevisionSchema(OrderResource::class),
        );
    }

    public function test_a_body_that_is_not_json_fails_the_assertion(): void
    {
        $this->route('/orders', static fn(): Response => new Response('<html></html>'));

        $this->expectFailure(
            'The response is not JSON (Syntax error): it cannot match the schema of Test\\Fixtures\\Resources\\OrderResource.',
            fn() => $this->getJson('/orders')->assertMatchesRevisionSchema(OrderResource::class, ApiRevision::Atlas),
        );
    }

    public function test_with_revision_keeps_a_query_parameter_the_request_names_and_the_fragment(): void
    {
        $this->resolvers([QueryResolver::class => []]);
        $this->route('/echo', static fn(Request $request): array => ['query' => $request->query(), 'revision' => ApiRevision::current()->value]);

        $this->withRevision(ApiRevision::Genesis)->getJson('/echo?revision=atlas')->assertExactJson(['query' => ['revision' => 'atlas'], 'revision' => 'atlas']);
        $this->withRevision(ApiRevision::Genesis)->getJson('/echo#top')->assertExactJson(['query' => ['revision' => 'genesis'], 'revision' => 'genesis']);
    }

    public function test_with_revision_refuses_an_invalid_resolver_name(): void
    {
        $this->resolvers([HeaderResolver::class => ['name' => '']]);

        $this->expectExceptionObject(ConfigurationException::invalidValue('resolvers.HeaderResolver.name', 'a non-empty string', 'string'));

        $this->withRevision(ApiRevision::Genesis);
    }

    public function test_an_empty_rendering_is_validated_as_an_object(): void
    {
        $this->assertRendersAtEveryRevision(NoteResource::class, new Order(['notes' => 'fragile']), [ApiRevision::Atlas]);
        $this->expectFailure(
            "The response does not match the schema of Test\\Fixtures\\Resources\\NoteResource at atlas:\n",
            fn() => $this->assertRendersAtEveryRevision(NoteResource::class, new Order(['notes' => null]), [ApiRevision::Atlas]),
        );
    }

    public function test_a_head_fixture_renders_at_every_revision(): void
    {
        $this->assertRendersAtEveryRevision(OrderResource::class, self::order());
        $this->assertRendersAtEveryRevision(OrderResource::class, self::order(), [ApiRevision::Genesis]);
    }

    public function test_a_field_outside_the_shape_fails_the_contract_test(): void
    {
        $this->expectFailure(
            "The response does not match the schema of Test\\Fixtures\\Resources\\NativeResource at atlas:\n",
            fn() => $this->assertRendersAtEveryRevision(NativeResource::class, new Order(['title' => 'Lamp', 'visible' => true]), [ApiRevision::Atlas]),
        );
    }

    /**
     * @param array<class-string, array<string, mixed>> $resolvers
     */
    private function resolvers(array $resolvers): void
    {
        $this->application()['config']->set('revisions.resolvers', $resolvers);
        $this->application()->forgetScopedInstances();
    }

    private function route(string $uri, Closure $action, bool $middleware = true): void
    {
        $route = $this->application()->make(Router::class)->get($uri, $action);
        if ($middleware) {
            $route->middleware('revision');
        }
    }

    /**
     * Runs `$assertion` and checks it fails with a message starting with `$message`.
     *
     * @param non-empty-string $message
     */
    private function expectFailure(string $message, Closure $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError $failure) {
            self::assertStringStartsWith($message, $failure->getMessage());

            return;
        }

        self::fail('The assertion passed.');
    }

    private static function order(): Order
    {
        $order = new Order([
            'title' => 'Lamp',
            'status' => OrderStatus::Paid,
            'total_cents' => 1250,
            'placed_at' => '2026-03-01 10:30:00',
            'tags' => ['gift'],
            'notes' => null,
            'visible' => true,
        ]);
        $order->setRelation('customer', new Customer(['name' => 'Ada Lovelace']));

        return $order;
    }
}
