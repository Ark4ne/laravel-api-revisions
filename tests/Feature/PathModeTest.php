<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Laravel\Http\ResolveRevision;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\HeaderResolver;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\PathResolver;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;

final class PathModeTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.resolvers', [PathResolver::class, HeaderResolver::class]);
    }

    public function test_a_revisioned_route_resolves_the_revision_from_the_path(): void
    {
        $this->routes();

        $this->getJson('/winter-25/orders/12')
            ->assertOk()
            ->assertJsonPath('revision', 'winter-25')
            ->assertHeader('Api-Revision', 'winter-25');
    }

    public function test_the_controller_does_not_receive_the_revision_parameter(): void
    {
        $this->routes();

        $this->getJson('/genesis/orders/12')
            ->assertJsonPath('order', '12')
            ->assertJsonPath('parameters', ['order' => '12']);
    }

    public function test_generated_urls_keep_the_revision(): void
    {
        $this->routes();

        $this->getJson('/spring-26/orders/12')->assertJsonPath('url', 'http://localhost/spring-26/orders/7');
    }

    public function test_a_request_resolved_by_header_generates_path_urls_in_its_revision(): void
    {
        $this->routes();
        $this->router()->get('/status', static fn(UrlGenerator $url): array => ['url' => $url->route('orders.show', ['order' => 7])])->middleware('revision');

        $this->getJson('/status', ['Api-Revision' => 'winter-25'])->assertJsonPath('url', 'http://localhost/winter-25/orders/7');
    }

    public function test_a_path_naming_no_revision_matches_no_route(): void
    {
        $this->routes();

        $this->getJson('/v1/orders/12')->assertNotFound();
        $this->getJson('/orders/12')->assertNotFound();
    }

    public function test_the_parameter_is_the_option_of_the_path_resolver(): void
    {
        $this->application()['config']->set('revisions.resolvers', [PathResolver::class => ['parameter' => 'version']]);
        $this->routes();

        $this->getJson('/atlas/orders/12')
            ->assertJsonPath('revision', 'atlas')
            ->assertJsonPath('parameters', ['order' => '12'])
            ->assertJsonPath('url', 'http://localhost/atlas/orders/7');
        self::assertSame('{version}/orders/{order}', $this->router()->getRoutes()->getByName('orders.show')?->uri());
    }

    public function test_a_route_already_in_the_revision_middleware_runs_it_once(): void
    {
        $this->router()->group(['middleware' => 'revision'], function (): void {
            $this->routes();
        });

        $this->getJson('/winter-25/orders/12')->assertJsonPath('revision', 'winter-25');
        $middleware = $this->router()->gatherRouteMiddleware($this->router()->getRoutes()->getByName('orders.show') ?? self::fail());
        self::assertCount(1, array_filter($middleware, static fn(mixed $name): bool => $name === ResolveRevision::class));
    }

    public function test_revisioned_routes_need_a_path_resolver(): void
    {
        $this->application()['config']->set('revisions.resolvers', [HeaderResolver::class]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Route::revisioned() reads the revision from the path: add Ark4ne\ApiRevisions\Laravel\Http\Resolvers\PathResolver to revisions.resolvers.');

        $this->routes();
    }

    private function routes(): void
    {
        $this->router()->revisioned(static function (Router $router): void {
            $router->get('/orders/{order}', static fn(Request $request, UrlGenerator $url, string $order): array => [
                'revision' => ApiRevision::current()->value,
                'order' => $order,
                'parameters' => $request->route()?->parameters(),
                'url' => $url->route('orders.show', ['order' => 7]),
            ])->name('orders.show');
        });
        $this->router()->getRoutes()->refreshNameLookups();
    }

    private function router(): Router
    {
        return $this->application()->make(Router::class);
    }
}
