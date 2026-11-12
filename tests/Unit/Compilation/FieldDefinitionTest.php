<?php

declare(strict_types=1);

namespace Test\Unit\Compilation;

use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Compilation\FieldVersion;
use Ark4ne\ApiRevisions\Conversion\ConversionChain;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class FieldDefinitionTest extends TestCase
{
    public function test_the_version_at_a_revision_is_the_one_of_its_was(): void
    {
        $winter = new FieldVersion(ApiRevision::Winter25, 0, 'total', TypeDefinition::of(Kind::String));
        $genesis = new FieldVersion(ApiRevision::Genesis, 1, 'total', TypeDefinition::of(Kind::Float));
        $field = self::field([$winter, $genesis]);

        self::assertSame($winter, $field->versionAt(ApiRevision::Winter25));
        self::assertSame($genesis, $field->versionAt(ApiRevision::Genesis));
    }

    public function test_a_revision_without_was_has_no_version(): void
    {
        $field = self::field([new FieldVersion(ApiRevision::Winter25, 0, 'state', TypeDefinition::of(Kind::String))]);

        self::assertNull($field->versionAt(ApiRevision::Spring26));
        self::assertNull($field->versionAt(ApiRevision::Genesis));
        self::assertNull(self::field([])->versionAt(ApiRevision::Winter25));
    }

    public function test_a_field_keeps_its_arguments(): void
    {
        $field = self::field([]);

        self::assertSame('status', $field->headKey);
        self::assertSame('state', $field->name);
        self::assertSame(ApiRevision::Genesis, $field->revision);
        self::assertNull($field->items);
        self::assertNull($field->children);
    }

    /**
     * @param list<FieldVersion> $versions
     */
    private static function field(array $versions): FieldDefinition
    {
        return new FieldDefinition(
            'status',
            ApiRevision::Genesis,
            'state',
            TypeDefinition::of(Kind::String),
            false,
            true,
            false,
            false,
            null,
            Lifetime::always(),
            null,
            $versions,
            ConversionChain::empty(Direction::Up),
        );
    }
}
