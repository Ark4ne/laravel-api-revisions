<?php

declare(strict_types=1);

namespace Test\Unit\Compilation;

use Ark4ne\ApiRevisions\Compilation\FieldVersion;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class FieldVersionTest extends TestCase
{
    public function test_a_version_keeps_its_arguments(): void
    {
        $type = TypeDefinition::of(Kind::String);

        $version = new FieldVersion(ApiRevision::Winter25, 0, 'state', $type);

        self::assertSame(ApiRevision::Winter25, $version->revision);
        self::assertSame(0, $version->change);
        self::assertSame('state', $version->name);
        self::assertSame($type, $version->type);
        self::assertNull($version->schemaChange);
        self::assertSame(1, (new FieldVersion(ApiRevision::Genesis, 1, 'state', $type, 1))->schemaChange);
    }
}
