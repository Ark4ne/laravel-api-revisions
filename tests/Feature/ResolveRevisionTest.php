<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Laravel\Http\ResolveRevision;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\HeaderResolver;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\QueryResolver;
use Ark4ne\ApiRevisions\Laravel\RevisionsServiceProvider;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\Router;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Resolvers\AccountPinnedResolver;

final class ResolveRevisionTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('app.timezone', 'Europe/Paris');
        $this->application()->make(Router::class)
            ->get('/orders', static fn(): array => ['revision' => ApiRevision::current()->value])
            ->middleware('revision');
    }

    public function test_the_middleware_alias_is_registered(): void
    {
        self::assertSame(ResolveRevision::class, $this->application()->make(Router::class)->getMiddleware()['revision'] ?? null);
    }

    public function test_the_controller_runs_in_the_revision_of_the_request(): void
    {
        $this->getJson('/orders', ['Api-Revision' => 'winter-25'])
            ->assertOk()
            ->assertExactJson(['revision' => 'winter-25']);
    }

    public function test_the_response_carries_the_revision_header(): void
    {
        $this->getJson('/orders', ['Api-Revision' => 'winter-25'])->assertHeader('Api-Revision', 'winter-25');
        $this->getJson('/orders')->assertHeader('Api-Revision', 'atlas')->assertJson(['revision' => 'atlas']);
    }

    public function test_the_response_header_is_configured(): void
    {
        $this->application()['config']->set('revisions.response_header', 'X-Api-Revision');

        $this->getJson('/orders', ['Api-Revision' => 'genesis'])
            ->assertHeader('X-Api-Revision', 'genesis')
            ->assertHeaderMissing('Api-Revision');
    }

    public function test_an_unknown_revision_is_answered_with_400(): void
    {
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects(self::never())->method('report');
        $this->application()->instance(ExceptionHandler::class, $handler);

        $this->getJson('/orders', ['Api-Revision' => '2020-01'])
            ->assertStatus(400)
            ->assertExactJson([
                'message' => "Unknown API revision '2020-01', expected one of: genesis, winter-25, spring-26, atlas.",
                'revision' => '2020-01',
                'accepted' => ['genesis', 'winter-25', 'spring-26', 'atlas'],
            ])
            ->assertHeaderMissing('Api-Revision');
    }

    public function test_an_unknown_revision_of_invalid_utf8_is_still_answered_in_json(): void
    {
        $this->getJson('/orders', ['Api-Revision' => "\xff\xfe"])
            ->assertStatus(400)
            ->assertJsonPath('accepted', ['genesis', 'winter-25', 'spring-26', 'atlas']);
    }

    public function test_a_deprecated_revision_adds_deprecation_and_sunset_headers(): void
    {
        $response = $this->getJson('/orders', ['Api-Revision' => 'spring-26']);

        // midnight of the day in the application timezone (Europe/Paris, UTC+1 in winter, UTC+2 in summer)
        $response->assertHeader('Deprecation', '@' . (new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('Europe/Paris')))->getTimestamp());
        $response->assertHeader('Sunset', 'Fri, 30 Apr 2027 22:00:00 GMT');
    }

    public function test_a_revision_without_dates_adds_no_deprecation_header(): void
    {
        $this->getJson('/orders', ['Api-Revision' => 'genesis'])
            ->assertHeaderMissing('Deprecation')
            ->assertHeaderMissing('Sunset');
    }

    public function test_header_mode_adds_vary(): void
    {
        $this->application()['config']->set('revisions.resolvers', [HeaderResolver::class => ['name' => 'X-Version'], QueryResolver::class]);

        $vary = $this->getJson('/orders?revision=genesis')->headers->all('vary');

        self::assertContains('X-Version', $vary);
    }

    public function test_query_mode_adds_no_vary(): void
    {
        $this->application()['config']->set('revisions.resolvers', [QueryResolver::class]);

        self::assertSame([], $this->getJson('/orders?revision=genesis')->headers->all('vary'));
    }

    public function test_the_vary_of_the_response_is_kept(): void
    {
        $this->application()->make(Router::class)
            ->get('/varied', static fn() => response()->json([])->setVary('Accept-Language'))
            ->middleware('revision');

        self::assertSame(['Accept-Language', 'Api-Revision'], $this->getJson('/varied')->headers->all('vary'));
    }

    public function test_without_application_timezone_the_days_are_read_in_utc(): void
    {
        $this->application()['config']->set('app.timezone', null);

        $this->getJson('/orders', ['Api-Revision' => 'spring-26'])
            ->assertHeader('Deprecation', '@' . (new DateTimeImmutable('2026-11-01 00:00:00+00:00'))->getTimestamp())
            ->assertHeader('Sunset', 'Sat, 01 May 2027 00:00:00 GMT');
    }

    public function test_an_unknown_revision_from_the_query_is_answered_with_400_varying_on_the_headers(): void
    {
        $this->application()['config']->set('revisions.resolvers', [HeaderResolver::class, QueryResolver::class]);

        $response = $this->getJson('/orders?revision=v9')->assertStatus(400)->assertJsonPath('revision', 'v9');

        self::assertSame(['Api-Revision'], $response->headers->all('vary'));
    }

    public function test_an_alias_of_the_application_is_kept(): void
    {
        $router = $this->application()->make(Router::class);
        $router->aliasMiddleware('revision', 'App\\Http\\Middleware\\Revision');

        (new RevisionsServiceProvider($this->application()))->boot();

        self::assertSame('App\\Http\\Middleware\\Revision', $router->getMiddleware()['revision']);
    }

    public function test_a_custom_resolver_varying_on_a_header_adds_it_to_vary(): void
    {
        $this->application()['config']->set('revisions.resolvers', [HeaderResolver::class, AccountPinnedResolver::class => ['accounts' => ['ada' => 'winter-25']]]);

        $response = $this->getJson('/orders', ['Authorization' => 'Bearer ada'])->assertJson(['revision' => 'winter-25']);

        self::assertSame(['Api-Revision', 'Authorization'], $response->headers->all('vary'));
    }
}
