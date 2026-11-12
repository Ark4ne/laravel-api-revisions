<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Laravel\RevisionsServiceProvider;
use Ark4ne\ApiRevisions\Projection\LenientValues;
use Ark4ne\ApiRevisions\Projection\StrictValues;
use Ark4ne\ApiRevisions\Projection\ValueErrorHandler;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use stdClass;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Throwable;

final class RevisionsServiceProviderTest extends FeatureTestCase
{
    public function test_the_config_file_is_merged_and_publishable(): void
    {
        self::assertSame('Api-Revision', $this->application()['config']->get('revisions.response_header'));
        self::assertSame(
            [dirname(__DIR__, 2) . '/src/Laravel/../../config/revisions.php' => $this->application()->configPath('revisions.php')],
            ServiceProvider::pathsToPublish(RevisionsServiceProvider::class, 'revisions-config'),
        );
    }

    public function test_an_enum_not_implementing_revision_is_refused_when_the_config_is_read(): void
    {
        $this->configure(['enum' => stdClass::class]);

        $this->expectException(ConfigurationException::class);

        $this->application()->make(RevisionsConfig::class);
    }

    public function test_outside_a_request_current_is_the_configured_fixed_default(): void
    {
        $this->configure(['enum' => ApiRevision::class, 'default' => 'winter-25']);

        self::assertSame(ApiRevision::Winter25, ApiRevision::current());
    }

    public function test_outside_a_request_a_resolver_default_gives_the_head(): void
    {
        $this->configure(['enum' => ApiRevision::class, 'default' => stdClass::class]);

        self::assertSame(ApiRevision::Atlas, ApiRevision::current());
    }

    public function test_the_scope_is_reset_between_two_requests(): void
    {
        $this->configure(['enum' => ApiRevision::class, 'default' => 'oldest']);
        $scope = RevisionScope::get();
        $scope->setBase(ApiRevision::Spring26);
        self::assertSame(ApiRevision::Spring26, ApiRevision::current());

        $this->application()->forgetScopedInstances();

        self::assertNotSame($scope, RevisionScope::get());
        self::assertSame(ApiRevision::Genesis, ApiRevision::current());
    }

    public function test_the_scope_is_the_one_of_the_current_container_as_under_octane(): void
    {
        $this->configure(['enum' => ApiRevision::class]);
        $original = $this->application();
        $sandbox = clone $original;
        Container::setInstance($sandbox);

        try {
            $sandbox->make(RevisionScope::class)->setBase(ApiRevision::Winter25);

            self::assertSame(ApiRevision::Winter25, ApiRevision::current());
        } finally {
            Container::setInstance($original);
        }
        self::assertSame(ApiRevision::Atlas, ApiRevision::current());
    }

    public function test_the_core_configuration_and_the_shapes_are_built_from_the_config_once(): void
    {
        $this->configure(['enum' => ApiRevision::class, 'date_format' => 'd/m/Y', 'strict_values' => true]);

        $configuration = $this->application()->make(Configuration::class);

        self::assertSame('d/m/Y', $configuration->dateFormat);
        self::assertSame($configuration, $this->application()->make(Configuration::class));
        self::assertSame($this->application()->make(Shapes::class), $this->application()->make(Shapes::class));
        self::assertInstanceOf(StrictValues::class, $this->application()->make(ValueErrorHandler::class));
    }

    public function test_strict_values_without_a_value_follow_app_debug(): void
    {
        $this->configure(['enum' => ApiRevision::class, 'strict_values' => null]);
        $this->application()['config']->set('app.debug', true);

        self::assertInstanceOf(StrictValues::class, $this->application()->make(ValueErrorHandler::class));
    }

    public function test_lenient_value_errors_are_reported_once_per_class_and_field(): void
    {
        $this->configure(['enum' => ApiRevision::class, 'strict_values' => false]);
        $reported = new class {
            /**
             * @var list<Throwable>
             */
            public array $errors = [];
        };
        $handler = self::createStub(ExceptionHandler::class);
        $handler->method('report')->willReturnCallback(static function (Throwable $error) use ($reported): void {
            $reported->errors[] = $error;
        });
        $this->application()->instance(ExceptionHandler::class, $handler);

        $values = $this->application()->make(ValueErrorHandler::class);
        self::assertInstanceOf(LenientValues::class, $values);
        $first = ValueException::nullNotAllowed('App\Order', 'total', ApiRevision::Genesis);
        self::assertSame('fallback', $values->handle($first, 'fallback'));
        $values->handle(ValueException::nullNotAllowed('App\Order', 'total', ApiRevision::Winter25), null);
        $other = ValueException::nullNotAllowed('App\Order', 'title', ApiRevision::Genesis);
        $values->handle($other, null);

        self::assertSame([$first, $other], $reported->errors);

        $this->application()->forgetScopedInstances();
        $again = ValueException::nullNotAllowed('App\Order', 'total', ApiRevision::Genesis);
        $this->application()->make(ValueErrorHandler::class)->handle($again, null);
        self::assertSame([$first, $other, $again], $reported->errors);
    }

    /**
     * @param array<string, mixed> $revisions
     */
    private function configure(array $revisions): void
    {
        $config = $this->application()['config'];
        foreach ($revisions as $key => $value) {
            $config->set("revisions.{$key}", $value);
        }
    }
}
