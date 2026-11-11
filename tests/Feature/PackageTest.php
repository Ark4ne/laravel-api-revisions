<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Laravel\RevisionsServiceProvider;
use Test\FeatureTestCase;

final class PackageTest extends FeatureTestCase
{
    public function test_the_package_boots_in_a_laravel_application(): void
    {
        self::assertSame([RevisionsServiceProvider::class], self::discoveredProviders());
        self::assertNotNull($this->application()->getProvider(RevisionsServiceProvider::class));
        self::assertTrue($this->application()->isBooted());
    }
}
