<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Checking\Lock;
use Ark4ne\ApiRevisions\Checking\LockedRevision;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Testing\PendingCommand;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\Declarations\InvalidDeclaration;
use Test\TemporaryDirectory;

final class RevisionLockTest extends FeatureTestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        CheckedDeclaration::$describe = static fn(): array => ['title' => Schema::string()];
        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.discovery', ['paths' => [], 'classes' => [CheckedDeclaration::class]]);
        $config->set('revisions.lock_path', $this->temporary);
    }

    protected function tearDown(): void
    {
        CheckedDeclaration::reset();

        parent::tearDown();
    }

    public function test_the_head_is_never_locked_by_default(): void
    {
        $this->command('revision:lock')
            ->expectsOutputToContain('Locked genesis, winter-25, spring-26.')
            ->assertSuccessful();

        self::assertSame(['genesis', 'spring-26', 'winter-25'], (new Lock($this->temporary))->locked());
        self::assertSame('winter-25', (new Lock($this->temporary))->read('spring-26')->previous);
        self::assertSame(['title' => ['type' => 'string']], (new Lock($this->temporary))->read('genesis')->classes[CheckedDeclaration::class]['response']['properties']);
    }

    public function test_a_published_lock_is_never_rewritten(): void
    {
        $published = new LockedRevision('winter-25', 'genesis', []);
        (new Lock($this->temporary))->write($published);

        $this->command('revision:lock')
            ->expectsOutputToContain('Locked genesis, spring-26.')
            ->assertSuccessful();

        self::assertEquals($published, (new Lock($this->temporary))->read('winter-25'));
    }

    public function test_nothing_to_lock_says_so(): void
    {
        $this->command('revision:lock')->assertSuccessful();

        $this->command('revision:lock')
            ->expectsOutputToContain('Every revision but the head is already locked.')
            ->assertSuccessful();
    }

    public function test_one_revision_is_locked_again_explicitly_the_head_included(): void
    {
        (new Lock($this->temporary))->write(new LockedRevision('winter-25', 'genesis', []));

        $this->command('revision:lock', ['--revision' => 'winter-25'])->expectsOutputToContain('Locked winter-25.')->assertSuccessful();
        $this->command('revision:lock', ['--revision' => 'atlas'])->expectsOutputToContain('Locked atlas.')->assertSuccessful();

        $lock = new Lock($this->temporary);
        self::assertSame(['atlas', 'winter-25'], $lock->locked());
        self::assertArrayHasKey(CheckedDeclaration::class, $lock->read('winter-25')->classes);
    }

    public function test_an_unknown_revision_is_refused(): void
    {
        $this->command('revision:lock', ['--revision' => 'autumn-24'])
            ->expectsOutputToContain('Unknown revision "autumn-24": genesis, winter-25, spring-26, atlas.')
            ->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporary);
    }

    public function test_a_declaration_that_does_not_build_fails_the_lock(): void
    {
        $this->application()['config']->set('revisions.discovery.classes', [InvalidDeclaration::class]);

        $this->command('revision:lock')->expectsOutputToContain(InvalidDeclaration::class)->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporary);
    }

    public function test_a_lock_that_cannot_be_written_fails_the_command(): void
    {
        file_put_contents($this->temporary, 'a file');

        $this->command('revision:lock')->expectsOutputToContain('cannot be written')->assertFailed();
    }

    public function test_the_check_compares_the_declarations_with_the_lock(): void
    {
        $this->command('revision:lock')->assertSuccessful();
        CheckedDeclaration::$describe = static fn(): array => ['title' => Schema::string(), 'note' => Schema::string()];

        $this->command('revision:check')
            ->expectsOutputToContain('lock-mismatch')
            ->expectsOutputToContain('3 errors, 0 warnings in 1 class.')
            ->assertFailed();
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
