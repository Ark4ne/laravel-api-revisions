<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Http\Resolvers;

use Ark4ne\ApiRevisions\Exceptions\UnknownRevisionException;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\HeaderResolver;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Illuminate\Http\Request;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class HeaderResolverTest extends TestCase
{
    public function test_the_header_names_the_revision(): void
    {
        $request = Request::create('/orders', server: ['HTTP_API_REVISION' => 'winter-25']);

        self::assertSame(ApiRevision::Winter25, (new HeaderResolver(self::config()))->resolve($request));
    }

    public function test_the_header_name_is_an_option(): void
    {
        $request = Request::create('/orders', server: ['HTTP_API_REVISION' => 'winter-25', 'HTTP_X_VERSION' => 'atlas']);

        self::assertSame(ApiRevision::Atlas, (new HeaderResolver(self::config(), 'X-Version'))->resolve($request));
    }

    public function test_without_header_it_gives_no_revision(): void
    {
        self::assertNull((new HeaderResolver(self::config()))->resolve(Request::create('/orders', parameters: ['revision' => 'atlas'])));
        self::assertNull((new HeaderResolver(self::config()))->resolve(Request::create('/orders', server: ['HTTP_API_REVISION' => ''])));
    }

    public function test_an_unknown_header_value_throws(): void
    {
        $this->expectException(UnknownRevisionException::class);

        (new HeaderResolver(self::config()))->resolve(Request::create('/orders', server: ['HTTP_API_REVISION' => '2020-01']));
    }

    private static function config(): RevisionsConfig
    {
        return RevisionsConfig::fromArray(['enum' => ApiRevision::class]);
    }
}
