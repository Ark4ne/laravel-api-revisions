<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Compilation\CompiledShapes;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Testing\InteractsWithRevisions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Testing\PendingCommand;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\ArticleDeclaration;
use Test\Fixtures\Declarations\StandIn;
use Test\TemporaryDirectory;

/**
 * A consumer's class implementing `Revisioned` itself goes through the whole tooling of the
 * Laravel layer: the check, the lock, the export, the changelog, the cache and the testing helpers.
 */
final class ConsumerDeclarationTest extends FeatureTestCase
{
    use InteractsWithRevisions;
    use TemporaryDirectory;

    private const array ARTICLE = ['title' => 'Tides', 'views' => 12, 'body' => 'Low and high.', 'published' => true, 'author' => ['name' => 'Ada']];

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.discovery', ['paths' => [], 'classes' => [ArticleDeclaration::class]]);
        $config->set('revisions.lock_path', $this->temporary . '/lock');
        $config->set('revisions.cache_path', $this->temporary . '/revisions.php');
        StandIn::$read = [];
        mkdir($this->temporary);
    }

    public function test_the_check_finds_it_consistent_without_reading_the_stand_in(): void
    {
        $this->command('revision:check', ['--list' => true])->expectsOutputToContain(ArticleDeclaration::class)->assertSuccessful();
        $this->command('revision:check')->expectsOutputToContain('The revisions of 1 class are consistent.')->assertSuccessful();
        $this->assertRevisionsAreConsistent();

        self::assertSame([], StandIn::$read);
    }

    public function test_it_is_locked_and_a_change_to_a_locked_revision_fails_the_check(): void
    {
        $this->command('revision:lock')->assertSuccessful();

        /** @var array{classes: array<string, mixed>} $lock */
        $lock = self::decode((string) file_get_contents($this->temporary . '/lock/winter-25.json'));
        self::assertArrayHasKey(ArticleDeclaration::class, $lock['classes']);
        $this->command('revision:check')->assertSuccessful();

        $locked = (string) file_get_contents($this->temporary . '/lock/winter-25.json');
        file_put_contents($this->temporary . '/lock/winter-25.json', str_replace('"headline"', '"title_was"', $locked));
        $this->command('revision:check')->expectsOutputToContain('lock-mismatch')->assertFailed();
    }

    public function test_it_is_exported_with_one_object_per_hook(): void
    {
        $kernel = $this->application()->make(Kernel::class);
        self::assertSame(0, $kernel->call('revision:export', ['--revision' => 'winter-25', '--class' => ArticleDeclaration::class]));
        /** @var array{properties: array<string, array{properties: array<string, mixed>}>} $schema */
        $schema = self::decode($kernel->output());

        self::assertSame(['attributes', 'meta'], array_keys($schema['properties']));
        self::assertSame(['headline', 'views'], array_keys($schema['properties']['attributes']['properties']));
        self::assertSame(['published', 'author'], array_keys($schema['properties']['meta']['properties']));
    }

    public function test_its_changes_are_in_the_changelog(): void
    {
        $this->command('revision:changelog', ['--from' => 'winter-25', '--to' => 'spring-26'])
            ->expectsOutputToContain('attributes.body')
            ->assertSuccessful();
    }

    public function test_its_shapes_are_cached(): void
    {
        $this->command('revision:cache')->assertSuccessful();

        $compiled = CompiledShapes::load($this->temporary . '/revisions.php');
        self::assertSame(4, $compiled->count());
        self::assertSame(['attributes', 'meta'], $compiled->get(ArticleDeclaration::class, DeclarationKind::Response, ApiRevision::Genesis)?->names());
    }

    public function test_it_renders_at_every_revision_through_the_application(): void
    {
        $app = $this->application();
        $render = static fn(ApiRevision $revision): array => ApiRevision::using($revision, static fn(): array => $app->make(Projector::class)->project(
            $app->make(Shapes::class)->for(ArticleDeclaration::class, DeclarationKind::Response, $revision),
            ArticleDeclaration::describeRevisioned(DeclarationKind::Response, self::ARTICLE),
            self::ARTICLE,
        ));

        self::assertSame(['attributes' => ['headline' => 'Tides', 'views' => '12'], 'meta' => ['published' => true, 'author' => 'Ada']], $render(ApiRevision::Genesis));
        self::assertSame(['attributes' => ['title' => 'Tides', 'views' => 12, 'body' => 'Low and high.'], 'meta' => ['published' => true]], $render(ApiRevision::Atlas));
        $this->assertRendersAtEveryRevision(ArticleDeclaration::class, self::ARTICLE);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
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
