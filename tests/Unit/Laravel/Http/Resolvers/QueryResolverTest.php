<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Http\Resolvers;

use Ark4ne\ApiRevisions\Exceptions\UnknownRevisionException;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\QueryResolver;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Illuminate\Http\Request;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class QueryResolverTest extends TestCase
{
    public function test_the_query_parameter_names_the_revision(): void
    {
        self::assertSame(ApiRevision::Genesis, (new QueryResolver(self::config()))->resolve(Request::create('/orders?revision=genesis')));
    }

    public function test_the_parameter_name_is_an_option(): void
    {
        self::assertSame(ApiRevision::Atlas, (new QueryResolver(self::config(), 'v'))->resolve(Request::create('/orders?revision=genesis&v=atlas')));
    }

    public function test_the_body_is_never_read(): void
    {
        self::assertNull((new QueryResolver(self::config()))->resolve(Request::create('/orders', 'POST', ['revision' => 'atlas'])));
    }

    public function test_without_parameter_it_gives_no_revision(): void
    {
        self::assertNull((new QueryResolver(self::config()))->resolve(Request::create('/orders')));
        self::assertNull((new QueryResolver(self::config()))->resolve(Request::create('/orders?revision=')));
    }

    public function test_an_array_parameter_is_unknown(): void
    {
        $this->expectException(UnknownRevisionException::class);
        $this->expectExceptionMessage("Unknown API revision 'array'");

        (new QueryResolver(self::config()))->resolve(Request::create('/orders?revision[]=atlas'));
    }

    private static function config(): RevisionsConfig
    {
        return RevisionsConfig::fromArray(['enum' => ApiRevision::class]);
    }
}
