<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\NonStrictDeclarations;
use Test\TestCase;

final class SchemaTest extends TestCase
{
    public function test_each_scalar_helper_returns_its_descriptor_with_its_source(): void
    {
        self::assertSame(Kind::String, Schema::string('ref')->toType()->kind);
        self::assertSame(['ref'], Schema::string('ref')->source()->path);
        self::assertSame(Kind::Int, Schema::int()->toType()->kind);
        self::assertSame(Kind::Float, Schema::float()->toType()->kind);
        self::assertSame(Kind::Bool, Schema::bool()->toType()->kind);
        self::assertTrue(Schema::bool()->source()->isDeclaredKey());
    }

    public function test_an_evaluated_value_is_refused_even_without_strict_types(): void
    {
        foreach (NonStrictDeclarations::evaluatedSources() as $index => $declare) {
            try {
                $declare();
                self::fail("Declaration {$index}: the evaluated value was taken for a key.");
            } catch (DefinitionException $exception) {
                self::assertStringStartsWith('A source is a key, a dotted path or a closure', $exception->getMessage());
            }
        }
    }
}
