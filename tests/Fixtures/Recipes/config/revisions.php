<?php

declare(strict_types=1);

use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\HeaderResolver;
use Test\Fixtures\Recipes\ApiRevision;
use Test\Fixtures\Recipes\Http\Resolvers\AccountPinnedResolver;

/*
 * The keys of config/revisions.php the recipes change, merged by RecipesTest over the package defaults.
 */
return [
    'enum' => ApiRevision::class,
    'resolvers' => [
        AccountPinnedResolver::class => [],
        HeaderResolver::class => ['name' => 'Api-Revision'],
    ],
];
