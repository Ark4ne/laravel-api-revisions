<?php

declare(strict_types=1);

namespace Test;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base of the Feature tests: a Laravel application (Orchestra Testbench) booted with the providers the package
 * declares for auto-discovery in composer.json, exactly as an application installing it would.
 */
abstract class FeatureTestCase extends BaseTestCase
{
    /**
     * @param Application $app
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return self::discoveredProviders();
    }

    /**
     * The application of the test, booted by setUp().
     */
    protected function application(): Application
    {
        return $this->app ?? throw new LogicException('The application is only available between setUp() and tearDown().');
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected static function discoveredProviders(): array
    {
        /** @var array{extra?: array{laravel?: array{providers?: list<class-string<ServiceProvider>>}}} $composer */
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        return $composer['extra']['laravel']['providers'] ?? [];
    }
}
