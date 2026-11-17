<?php

declare(strict_types=1);

namespace Test\Fixtures;

use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Schema\DeclaresConversions;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\InvalidValue;

/**
 * A custom type whose fragment names the related type, as a JSON:API relationship does: its values are
 * the same whatever the related type, so it declares the identity of its fragments.
 *
 * @extends Descriptor<array<array-key, mixed>>
 */
final class RelationSchema extends Descriptor implements DeclaresConversions
{
    public static function conversions(Converters $converters): Converters
    {
        return $converters->identity('relation');
    }

    /**
     * @param non-empty-string $related the related type the fragment names
     */
    public function __construct(private readonly string $related, mixed $source = null)
    {
        parent::__construct($source);
    }

    public function normalize(mixed $value): mixed
    {
        return is_array($value) && is_string($value['type'] ?? null) && is_string($value['id'] ?? null)
            ? $value
            : new InvalidValue($value, Kind::Custom, null);
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::custom('relation', [
            'type' => 'object',
            'properties' => ['type' => ['const' => $this->related], 'id' => ['type' => 'string']],
        ]);
    }
}
