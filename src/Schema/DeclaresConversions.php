<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Conversion\Converters;

/**
 * Implemented by a custom type (a `Descriptor` registered in the `TypeRegistry`) whose values convert automatically
 * to or from other types (model.md §5): `TypeRegistry::converters()` adds its conversions to the registry the shapes
 * are planned against.
 */
interface DeclaresConversions
{
    /**
     * `$converters` with the conversions of this type registered, by the name its `TypeDefinition::custom()` declares
     * (`$converters->register('money', Kind::Float, Direction::Down, …)`).
     */
    public static function conversions(Converters $converters): Converters;
}
