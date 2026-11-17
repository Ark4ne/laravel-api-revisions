<?php

declare(strict_types=1);

namespace Test\Fixtures;

use Ark4ne\ApiRevisions\Schema\ObjectSchema;
use Ark4ne\ApiRevisions\Schema\Schema;

/**
 * The object every fixture shares as the component `Address`: its `zip` exists since Spring26.
 */
final class Address
{
    public static function schema(?string $source = null): ObjectSchema
    {
        return Schema::object([
            'street' => Schema::string(),
            'zip' => Schema::string()->since(ApiRevision::Spring26),
        ], $source)->component('Address');
    }
}
