<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Definition;

/**
 * The kind of a type: what the projection normalizes to, the conversions key on and the export describes.
 */
enum Kind: string
{
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case Date = 'date';
    case DateTime = 'date-time';
    case Enum = 'enum';
    case List = 'list';
    case Object = 'object';
    case Custom = 'custom';
    case Any = 'any';
}
