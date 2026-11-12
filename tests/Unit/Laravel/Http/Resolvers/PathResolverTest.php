<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Http\Resolvers;

use Ark4ne\ApiRevisions\Exceptions\UnknownRevisionException;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\PathResolver;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class PathResolverTest extends TestCase
{
    public function test_the_route_parameter_names_the_revision(): void
    {
        self::assertSame(ApiRevision::Spring26, (new PathResolver(self::config()))->resolve(self::request('{revision}/orders', '/spring-26/orders')));
    }

    public function test_the_parameter_name_is_an_option(): void
    {
        self::assertSame(ApiRevision::Atlas, (new PathResolver(self::config(), 'v'))->resolve(self::request('{v}/orders', '/atlas/orders')));
    }

    public function test_outside_a_route_or_without_parameter_it_gives_no_revision(): void
    {
        self::assertNull((new PathResolver(self::config()))->resolve(Request::create('/atlas/orders')));
        self::assertNull((new PathResolver(self::config()))->resolve(self::request('{version}/orders', '/atlas/orders')));
    }

    public function test_an_unknown_path_segment_throws(): void
    {
        $this->expectException(UnknownRevisionException::class);

        (new PathResolver(self::config()))->resolve(self::request('{revision}/orders', '/v1/orders'));
    }

    public function test_it_names_the_parameter_it_reads(): void
    {
        self::assertSame('revision', (new PathResolver(self::config()))->parameter());
        self::assertSame('v', (new PathResolver(self::config(), 'v'))->parameter());
    }

    private static function request(string $uri, string $path): Request
    {
        $request = Request::create($path);
        $route = (new Route('GET', $uri, static fn(): null => null))->bind($request);
        $request->setRouteResolver(static fn(): Route => $route);

        return $request;
    }

    private static function config(): RevisionsConfig
    {
        return RevisionsConfig::fromArray(['enum' => ApiRevision::class]);
    }
}
