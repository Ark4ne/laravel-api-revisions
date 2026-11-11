<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the library in a Laravel application (auto-discovered). Configuration, scope, middleware, route macro
 * and commands are added by their stories (US 8.1 onwards).
 */
final class RevisionsServiceProvider extends ServiceProvider
{
    public function register(): void {}
}
