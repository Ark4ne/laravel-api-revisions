<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Http\Resolvers;

use Ark4ne\ApiRevisions\Exceptions\UnknownRevisionException;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\HeaderResolver;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\QueryResolver;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\ResolverChain;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Illuminate\Http\Request;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Resolvers\AccountPinnedResolver;
use Test\TestCase;

final class ResolverChainTest extends TestCase
{
    public function test_the_first_resolver_returning_a_revision_wins(): void
    {
        $chain = new ResolverChain(ApiRevision::class, [new HeaderResolver(self::config()), new QueryResolver(self::config())], ApiRevision::Atlas);

        self::assertSame(ApiRevision::Genesis, $chain->resolve(Request::create('/orders?revision=genesis', server: ['HTTP_API_REVISION' => ''])));
        self::assertSame(ApiRevision::Winter25, $chain->resolve(Request::create('/orders?revision=genesis', server: ['HTTP_API_REVISION' => 'winter-25'])));
    }

    public function test_the_default_applies_when_no_resolver_answers(): void
    {
        $chain = new ResolverChain(ApiRevision::class, [new HeaderResolver(self::config())], ApiRevision::Spring26);

        self::assertSame(ApiRevision::Spring26, $chain->resolve(Request::create('/orders')));
        self::assertSame(ApiRevision::Spring26, (new ResolverChain(ApiRevision::class, [], ApiRevision::Spring26))->resolve(Request::create('/orders')));
    }

    public function test_a_resolver_as_default_answers_after_the_chain(): void
    {
        $chain = new ResolverChain(ApiRevision::class, [new HeaderResolver(self::config())], new AccountPinnedResolver(['ada' => 'winter-25']));

        self::assertSame(ApiRevision::Winter25, $chain->resolve(Request::create('/orders', server: ['HTTP_AUTHORIZATION' => 'Bearer ada'])));
        self::assertSame(ApiRevision::Genesis, $chain->resolve(Request::create('/orders', server: ['HTTP_AUTHORIZATION' => 'Bearer ada', 'HTTP_API_REVISION' => 'genesis'])));
    }

    public function test_a_resolver_as_default_answering_nothing_gives_the_head(): void
    {
        $chain = new ResolverChain(ApiRevision::class, [], new AccountPinnedResolver());

        self::assertSame(ApiRevision::Atlas, $chain->resolve(Request::create('/orders')));
    }

    public function test_an_unknown_value_stops_the_chain(): void
    {
        $chain = new ResolverChain(ApiRevision::class, [new HeaderResolver(self::config()), new QueryResolver(self::config())], ApiRevision::Atlas);

        $this->expectException(UnknownRevisionException::class);

        $chain->resolve(Request::create('/orders?revision=genesis', server: ['HTTP_API_REVISION' => 'v2']));
    }

    private static function config(): RevisionsConfig
    {
        return RevisionsConfig::fromArray(['enum' => ApiRevision::class]);
    }
}
