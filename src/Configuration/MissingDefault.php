<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Configuration;

/**
 * What a `required()->since(R)` field declared without `default:` receives for clients older than `R`: nothing, reported by the check (`Error`), or `null` (`Null`).
 */
enum MissingDefault: string
{
    case Error = 'error';
    case Null = 'null';
}
