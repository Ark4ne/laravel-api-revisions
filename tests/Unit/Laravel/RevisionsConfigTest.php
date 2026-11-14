<?php

declare(strict_types=1);

namespace Test\Unit\Laravel;

use Ark4ne\ApiRevisions\Configuration\MissingDefault;
use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\HeaderResolver;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\PathResolver;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\Unrevisioned;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Resolvers\AbstractResolver;
use Test\Fixtures\Resolvers\AccountPinnedResolver;
use Test\Fixtures\Revisions\IntRevision;
use Test\TestCase;

final class RevisionsConfigTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notRevisionEnums(): iterable
    {
        yield 'not a string' => [12];
        yield 'not a class' => ['App\Missing'];
        yield 'a class' => [stdClass::class];
        yield 'a backed enum not implementing Revision' => [OrderStatus::class];
    }

    #[DataProvider('notRevisionEnums')]
    public function test_an_enum_not_implementing_revision_is_refused(mixed $enum): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('revisions.enum must be a backed enum implementing Ark4ne\ApiRevisions\Revisions\Revision');

        RevisionsConfig::fromArray(['enum' => $enum]);
    }

    public function test_a_missing_enum_selects_the_implicit_revision(): void
    {
        $config = RevisionsConfig::fromArray(['resolvers' => [HeaderResolver::class => []]]);

        self::assertSame(Unrevisioned::class, $config->enum);
        self::assertFalse($config->revisioned);
        self::assertSame([], $config->resolvers);
        self::assertSame(Unrevisioned::Head, $config->default);
        self::assertSame(Unrevisioned::Head, RevisionsConfig::fromArray(['enum' => null, 'default' => 'oldest'])->default);
    }

    public function test_a_configured_enum_is_revisioned(): void
    {
        self::assertTrue(RevisionsConfig::fromArray(['enum' => ApiRevision::class])->revisioned);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function defaultsWithoutEnum(): iterable
    {
        yield 'the backed value of the implicit revision' => ['head'];
        yield 'a resolver class' => [HeaderResolver::class];
        yield 'not a string' => [3];
    }

    #[DataProvider('defaultsWithoutEnum')]
    public function test_a_default_but_latest_or_oldest_without_enum_is_refused(mixed $default): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("revisions.default must be 'latest' or 'oldest' (no revision enum configured)");

        RevisionsConfig::fromArray(['default' => $default]);
    }

    public function test_a_complete_configuration_is_read(): void
    {
        $config = RevisionsConfig::fromArray([
            'enum' => ApiRevision::class,
            'resolvers' => [HeaderResolver::class => ['name' => 'X-Revision'], AccountPinnedResolver::class],
            'default' => 'oldest',
            'response_header' => 'X-Revision',
            'missing_default' => 'null',
            'strict_values' => false,
            'date_format' => 'd/m/Y',
            'date_time_format' => DATE_ATOM,
            'discovery' => ['paths' => ['/app', '/modules'], 'classes' => [stdClass::class]],
            'cache_path' => '/cache/revisions.php',
            'lock_path' => '/revisions.lock',
        ], debug: true);

        self::assertSame(ApiRevision::class, $config->enum);
        self::assertSame([[HeaderResolver::class, ['name' => 'X-Revision']], [AccountPinnedResolver::class, []]], $config->resolvers);
        self::assertSame(ApiRevision::Genesis, $config->default);
        self::assertSame('X-Revision', $config->responseHeader);
        self::assertSame(MissingDefault::Null, $config->configuration->missingDefault);
        self::assertFalse($config->configuration->strictValues);
        self::assertSame('d/m/Y', $config->configuration->dateFormat);
        self::assertSame(DATE_ATOM, $config->configuration->dateTimeFormat);
        self::assertSame(['/app', '/modules'], $config->discoveryPaths);
        self::assertSame([stdClass::class], $config->discoveryClasses);
        self::assertSame('/cache/revisions.php', $config->cachePath);
        self::assertSame('/revisions.lock', $config->lockPath);
    }

    public function test_missing_keys_take_the_defaults_of_the_config_file(): void
    {
        $config = RevisionsConfig::fromArray(['enum' => ApiRevision::class]);

        self::assertSame([[HeaderResolver::class, []]], $config->resolvers);
        self::assertSame(ApiRevision::Atlas, $config->default);
        self::assertSame('Api-Revision', $config->responseHeader);
        self::assertSame(MissingDefault::Error, $config->configuration->missingDefault);
        self::assertSame('Y-m-d', $config->configuration->dateFormat);
        self::assertSame('Y-m-d\TH:i:sP', $config->configuration->dateTimeFormat);
        self::assertSame([], $config->discoveryPaths);
        self::assertSame([], $config->discoveryClasses);
        self::assertSame('bootstrap/cache/revisions.php', $config->cachePath);
        self::assertSame('revisions.lock', $config->lockPath);
    }

    public function test_strict_values_without_a_value_follow_the_debug_mode(): void
    {
        self::assertTrue(RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'strict_values' => null], debug: true)->configuration->strictValues);
        self::assertFalse(RevisionsConfig::fromArray(['enum' => ApiRevision::class], debug: false)->configuration->strictValues);
        self::assertFalse(RevisionsConfig::fromArray(['enum' => ApiRevision::class])->configuration->strictValues);
        self::assertTrue(RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'strict_values' => true], debug: false)->configuration->strictValues);
    }

    public function test_a_fixed_default_is_resolved_to_its_revision(): void
    {
        self::assertSame(ApiRevision::Atlas, RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'default' => 'latest'])->fixedDefault());
        self::assertSame(ApiRevision::Genesis, RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'default' => 'oldest'])->fixedDefault());
        self::assertSame(ApiRevision::Spring26, RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'default' => 'spring-26'])->fixedDefault());
        self::assertSame(IntRevision::Second, RevisionsConfig::fromArray(['enum' => IntRevision::class, 'default' => 3])->fixedDefault());
        self::assertSame(IntRevision::Second, RevisionsConfig::fromArray(['enum' => IntRevision::class, 'default' => '3'])->fixedDefault());
    }

    public function test_a_resolver_class_as_default_gives_no_fixed_revision(): void
    {
        $config = RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'default' => AccountPinnedResolver::class]);

        self::assertSame(AccountPinnedResolver::class, $config->default);
        self::assertNull($config->fixedDefault());
    }

    public function test_the_path_parameter_is_the_option_of_the_first_path_resolver(): void
    {
        self::assertSame('revision', RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'resolvers' => [HeaderResolver::class, PathResolver::class]])->pathParameter());
        self::assertSame('v', RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'resolvers' => [HeaderResolver::class, PathResolver::class => ['parameter' => 'v'], 1 => PathResolver::class]])->pathParameter());
    }

    public function test_a_path_resolver_as_default_reads_the_revision_parameter(): void
    {
        self::assertSame('revision', RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'default' => PathResolver::class])->pathParameter());
    }

    public function test_the_path_parameter_needs_a_path_resolver(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Route::revisioned() reads the revision from the path');

        RevisionsConfig::fromArray(['enum' => ApiRevision::class])->pathParameter();
    }

    public function test_a_path_parameter_option_that_is_not_a_name_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('revisions.resolvers.PathResolver.parameter must be a non-empty string, got int.');

        RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'resolvers' => [PathResolver::class => ['parameter' => 1]]])->pathParameter();
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'default' => ['default', 'unknown', "revisions.default must be 'latest', 'oldest', a backed value of Test\\Fixtures\\ApiRevision or a resolver class, got unknown."];
        yield 'default as an array' => ['default', ['latest'], "revisions.default must be 'latest', 'oldest', a backed value of Test\\Fixtures\\ApiRevision or a resolver class, got array."];
        yield 'default a class not implementing the contract' => ['default', stdClass::class, "revisions.default must be 'latest', 'oldest', a backed value of Test\\Fixtures\\ApiRevision or a resolver class, got stdClass."];
        yield 'default of another type' => ['default', 1.5, "revisions.default must be 'latest', 'oldest', a backed value of Test\\Fixtures\\ApiRevision or a resolver class, got float."];
        yield 'missing_default' => ['missing_default', 'none', "revisions.missing_default must be 'error' or 'null', got string."];
        yield 'strict_values' => ['strict_values', 'yes', 'revisions.strict_values must be a bool or null, got string.'];
        yield 'date_format' => ['date_format', '', 'revisions.date_format must be a non-empty string, got string.'];
        yield 'date_time_format' => ['date_time_format', 1, 'revisions.date_time_format must be a non-empty string, got int.'];
        yield 'response_header' => ['response_header', '', 'revisions.response_header must be a non-empty string, got string.'];
        yield 'response_header with a space' => ['response_header', 'Api Revision', 'revisions.response_header must be a header name (an HTTP token), got string.'];
        yield 'resolvers' => ['resolvers', 'header', 'revisions.resolvers must be an array, got string.'];
        yield 'discovery' => ['discovery', 'app', 'revisions.discovery must be an array, got string.'];
        yield 'cache_path' => ['cache_path', '', 'revisions.cache_path must be a non-empty string, got string.'];
        yield 'lock_path' => ['lock_path', null, 'revisions.lock_path must be a non-empty string, got null.'];
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function invalidResolvers(): iterable
    {
        yield 'a missing class' => [['App\\Missing' => []], 'App\\Missing'];
        yield 'a class not implementing the contract' => [[stdClass::class], stdClass::class];
        yield 'an abstract class' => [[AbstractResolver::class], AbstractResolver::class];
        yield 'not a class name' => [[12], 'int'];
        yield 'options not an array' => [[HeaderResolver::class => 'X-Revision'], 'string'];
        yield 'options not named' => [[HeaderResolver::class => ['X-Revision']], 'list'];
    }

    /**
     * @param array<array-key, mixed> $resolvers
     */
    #[DataProvider('invalidResolvers')]
    public function test_a_resolver_not_implementing_the_contract_is_refused(array $resolvers, string $given): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("revisions.resolvers must be classes implementing Ark4ne\\ApiRevisions\\Laravel\\Http\\RevisionResolver, each optionally => its options by name, got {$given}.");

        RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'resolvers' => $resolvers]);
    }

    #[DataProvider('invalidValues')]
    public function test_an_invalid_value_is_refused(string $key, mixed $value, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        RevisionsConfig::fromArray(['enum' => ApiRevision::class, $key => $value]);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidDiscoveries(): iterable
    {
        yield 'paths' => [['paths' => 'app'], 'revisions.discovery.paths must be a list of strings, got string.'];
        yield 'a path' => [['paths' => [1]], 'revisions.discovery.paths must be a list of strings, got array.'];
        yield 'classes' => [['classes' => ['a' => 'App\Order']], 'revisions.discovery.classes must be a list of strings, got array.'];
    }

    #[DataProvider('invalidDiscoveries')]
    public function test_an_invalid_discovery_is_refused(mixed $discovery, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        RevisionsConfig::fromArray(['enum' => ApiRevision::class, 'discovery' => $discovery]);
    }
}
