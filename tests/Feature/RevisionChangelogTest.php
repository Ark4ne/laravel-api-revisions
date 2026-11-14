<?php

declare(strict_types=1);

namespace Test\Feature;

use Illuminate\Testing\PendingCommand;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\InvalidDeclaration;
use Test\Fixtures\Declarations\OrderDeclaration;

final class RevisionChangelogTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.discovery', ['paths' => [], 'classes' => [OrderDeclaration::class, CustomerDeclaration::class]]);
    }

    public function test_the_changelog_command_prints_every_revision_after_the_oldest(): void
    {
        $this->command('revision:changelog')
            ->expectsOutputToContain('## atlas')
            ->expectsOutputToContain('### Test\Fixtures\Declarations\CustomerDeclaration (request-body)')
            ->expectsOutputToContain('- Renamed `full_name` to `name`.')
            ->expectsOutputToContain('## winter-25')
            ->doesntExpectOutputToContain('## genesis')
            ->assertSuccessful();
    }

    public function test_the_bounds_narrow_the_changelog(): void
    {
        $this->command('revision:changelog', ['--from' => 'winter-25', '--to' => 'spring-26'])
            ->expectsOutputToContain('## spring-26')
            ->doesntExpectOutputToContain('## atlas')
            ->doesntExpectOutputToContain('## winter-25')
            ->assertSuccessful();
    }

    public function test_an_unknown_revision_is_refused(): void
    {
        $this->command('revision:changelog', ['--to' => 'autumn-24'])
            ->expectsOutputToContain('Unknown revision "autumn-24": genesis, winter-25, spring-26, atlas.')
            ->assertFailed();
    }

    public function test_the_base_must_be_older_than_the_last_revision(): void
    {
        $this->command('revision:changelog', ['--from' => 'atlas', '--to' => 'winter-25'])
            ->expectsOutputToContain('--from (atlas) must be older than --to (winter-25).')
            ->assertFailed();
    }

    public function test_a_declaration_that_does_not_build_fails_the_command(): void
    {
        $this->application()['config']->set('revisions.discovery.classes', [InvalidDeclaration::class]);

        $this->command('revision:changelog')->expectsOutputToContain(InvalidDeclaration::class)->assertFailed();
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
