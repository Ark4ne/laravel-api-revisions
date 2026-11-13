<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Laravel\Discovery\PathClassFinder;
use Illuminate\Testing\PendingCommand;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Checked\FaultyDeclaration;
use Test\Fixtures\Checked\WarnedDeclaration;
use Test\Fixtures\Declarations\EagerDeclaration;
use Test\Fixtures\Resources\NativeResource;

final class RevisionCheckTest extends FeatureTestCase
{
    private const string BEFORE_SINCE = "The was(Genesis) of 'total' (request-body) is older than its since(Winter25): it describes no revision.";

    private const string WITHOUT_DEFAULT = "'priority' (request-body) is required since Atlas without default: older clients never send it; give since(Atlas, default: ...).";

    private const string REPEATED = "since() is called more than once on 'title' (response): the last call replaces Genesis.";

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.discovery', ['paths' => [dirname(__DIR__) . '/Fixtures/Checked'], 'classes' => []]);
    }

    public function test_the_check_command_fails_on_an_error(): void
    {
        $this->command('revision:check')
            ->expectsTable(['Severity', 'Code', 'Class', 'Field', 'Revision', 'Message'], [
                ['error', 'history-before-since', FaultyDeclaration::class, 'total', 'genesis', self::BEFORE_SINCE],
                ['error', 'required-since-without-default', FaultyDeclaration::class, 'priority', 'atlas', self::WITHOUT_DEFAULT],
                ['warning', 'bound-repeated', WarnedDeclaration::class, 'title', 'genesis', self::REPEATED],
            ])
            ->expectsOutputToContain('2 errors, 1 warning in 2 classes.')
            ->assertFailed();
    }

    public function test_warnings_alone_pass_the_check(): void
    {
        $this->application()['config']->set('revisions.discovery.paths', [dirname(__DIR__) . '/Fixtures/Checked/WarnedDeclaration.php']);

        $this->command('revision:check')
            ->expectsOutputToContain('0 errors, 1 warning in 1 class.')
            ->assertSuccessful();
    }

    public function test_consistent_revisions_pass_the_check(): void
    {
        $this->application()['config']->set('revisions.discovery.paths', []);

        $this->command('revision:check')
            ->expectsOutputToContain('The revisions of 0 classes are consistent.')
            ->assertSuccessful();
    }

    public function test_the_check_command_prints_json(): void
    {
        $this->application()['config']->set('revisions.discovery.paths', [dirname(__DIR__) . '/Fixtures/Checked/WarnedDeclaration.php']);

        $this->command('revision:check', ['--format' => 'json'])
            ->expectsOutput(json_encode([
                'classes' => 1,
                'errors' => 0,
                'warnings' => 1,
                'diagnostics' => [
                    ['code' => 'bound-repeated', 'severity' => 'warning', 'class' => WarnedDeclaration::class, 'field' => 'title', 'revision' => 'genesis', 'message' => self::REPEATED],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->assertSuccessful();
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->command('revision:check', ['--format' => 'xml'])
            ->expectsOutputToContain('Unknown format "xml": use table or json.')
            ->assertFailed();
    }

    public function test_the_list_option_prints_the_discovered_classes(): void
    {
        $this->application()['config']->set('revisions.discovery.classes', [NativeResource::class]);

        $this->command('revision:check', ['--list' => true])
            ->expectsOutput(FaultyDeclaration::class)
            ->expectsOutput(WarnedDeclaration::class)
            ->expectsOutput(NativeResource::class)
            ->expectsOutputToContain('3 classes discovered.')
            ->assertSuccessful();
    }

    public function test_the_list_option_prints_json(): void
    {
        $this->command('revision:check', ['--list' => true, '--format' => 'json'])
            ->expectsOutput(json_encode([FaultyDeclaration::class, WarnedDeclaration::class], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->assertSuccessful();
    }

    public function test_an_invalid_discovery_entry_fails_the_command_with_its_reason(): void
    {
        $this->application()['config']->set('revisions.discovery.classes', ['App\\Missing']);

        $this->command('revision:check')
            ->expectsOutputToContain('revisions.discovery.classes must be a list of concrete classes implementing')
            ->assertFailed();
    }

    public function test_explicit_classes_outside_the_paths_are_checked(): void
    {
        $config = $this->application()['config'];
        $config->set('revisions.discovery.paths', []);
        $config->set('revisions.discovery.classes', [EagerDeclaration::class, NativeResource::class]);

        $this->command('revision:check')->expectsOutputToContain('definition-error')->assertFailed();

        $reported = array_map(
            static fn($diagnostic): array => [$diagnostic->code, $diagnostic->class],
            $this->application()->make(Checker::class)->run()->all(),
        );
        self::assertContains(['definition-error', EagerDeclaration::class], $reported);
        self::assertContains(['native-condition', NativeResource::class], $reported);
    }

    public function test_the_check_reads_the_configuration_of_the_application(): void
    {
        $this->application()['config']->set('revisions.missing_default', 'null');

        $this->command('revision:check')
            ->doesntExpectOutputToContain('required-since-without-default')
            ->expectsOutputToContain('1 error, 1 warning in 2 classes.')
            ->assertFailed();
    }

    public function test_the_container_gives_the_checker_and_the_finder_of_the_discovery(): void
    {
        $finder = $this->application()->make(ClassFinder::class);

        self::assertInstanceOf(PathClassFinder::class, $finder);
        self::assertSame([FaultyDeclaration::class, WarnedDeclaration::class], $finder->find());
        $diagnostics = $this->application()->make(Checker::class)->run();
        self::assertCount(2, $diagnostics->errors());
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
