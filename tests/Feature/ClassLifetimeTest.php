<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Compilation\CompiledShapes;
use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Testing\InteractsWithRevisions;
use Illuminate\Contracts\Console\Kernel;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\Declarations\PodcastDeclaration;
use Test\Fixtures\Declarations\ShowDeclaration;
use Test\TemporaryDirectory;

/**
 * A class added in `spring-26` (US 12.3, model.md D81) goes through the tooling of the Laravel layer only at the
 * revisions it exists in: the check, the lock, the export, the changelog, the cache and the testing helpers.
 */
final class ClassLifetimeTest extends FeatureTestCase
{
    use InteractsWithRevisions;
    use TemporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.discovery', ['paths' => [], 'classes' => [PodcastDeclaration::class, ShowDeclaration::class]]);
        $config->set('revisions.lock_path', $this->temporary . '/lock');
        $config->set('revisions.cache_path', $this->temporary . '/revisions.php');
        mkdir($this->temporary);
    }

    public function test_the_check_and_the_testing_helpers_skip_the_revisions_it_does_not_exist_in(): void
    {
        self::assertSame(0, $this->kernel()->call('revision:check'));
        $this->assertRevisionsAreConsistent();
        $this->assertRendersAtEveryRevision(PodcastDeclaration::class, ['title' => 'Tides', 'minutes' => 42]);
        $this->assertRendersAtEveryRevision(ShowDeclaration::class, ['name' => 'Ebb', 'podcast' => ['title' => 'Tides', 'minutes' => 42]]);
    }

    public function test_a_lock_file_lists_the_classes_existing_at_its_revision(): void
    {
        self::assertSame(0, $this->kernel()->call('revision:lock'));

        self::assertSame([ShowDeclaration::class], array_keys(self::classes($this->temporary . '/lock/winter-25.json')));
        self::assertSame([PodcastDeclaration::class, ShowDeclaration::class], array_keys(self::classes($this->temporary . '/lock/spring-26.json')));
        self::assertSame(0, $this->kernel()->call('revision:check'));
    }

    public function test_the_export_leaves_it_out_of_the_revisions_it_does_not_exist_in(): void
    {
        $kernel = $this->kernel();
        self::assertSame(0, $kernel->call('revision:export'));
        $export = self::decode($kernel->output());

        self::assertSame([ShowDeclaration::class], array_keys(self::array($export['winter-25'])));
        self::assertSame([PodcastDeclaration::class, ShowDeclaration::class], array_keys(self::array($export['spring-26'])));
        self::assertSame(0, $kernel->call('revision:export', ['--class' => PodcastDeclaration::class]));
        self::assertSame(['spring-26', 'atlas'], array_keys(self::decode($kernel->output())));
    }

    public function test_exporting_it_at_a_revision_it_does_not_exist_in_fails(): void
    {
        $kernel = $this->kernel();

        self::assertSame(1, $kernel->call('revision:export', ['--class' => PodcastDeclaration::class, '--revision' => 'winter-25']));
        self::assertStringContainsString(PodcastDeclaration::class . ' does not exist at ' . ApiRevision::class . '::Winter25', $kernel->output());

        CheckedDeclaration::$kinds = [DeclarationKind::Response, DeclarationKind::RequestBody];
        CheckedDeclaration::$lifetime = Lifetime::since(ApiRevision::Spring26);
        try {
            self::assertSame(1, $kernel->call('revision:export', ['--class' => CheckedDeclaration::class, '--revision' => 'winter-25']));
            self::assertStringContainsString(CheckedDeclaration::class . ' does not exist at', $kernel->output());
        } finally {
            CheckedDeclaration::reset();
        }
    }

    public function test_the_changelog_lists_it_added_in_its_first_revision(): void
    {
        $kernel = $this->kernel();
        self::assertSame(0, $kernel->call('revision:changelog', ['--from' => 'winter-25', '--to' => 'spring-26']));

        self::assertStringContainsString('### ' . PodcastDeclaration::class . " (response)\n\n- The class is added.", $kernel->output());
    }

    public function test_the_cache_holds_its_shapes_at_the_revisions_it_exists_in_only(): void
    {
        self::assertSame(0, $this->kernel()->call('revision:cache'));

        $compiled = CompiledShapes::load($this->temporary . '/revisions.php');
        self::assertSame(6, $compiled->count());
        self::assertNull($compiled->get(PodcastDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25));
        self::assertSame(['title', 'duration'], $compiled->get(PodcastDeclaration::class, DeclarationKind::Response, ApiRevision::Spring26)?->names());
    }

    private function kernel(): Kernel
    {
        return $this->application()->make(Kernel::class);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function classes(string $file): array
    {
        return self::array(self::decode((string) file_get_contents($file))['classes'] ?? null);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $json): array
    {
        return self::array(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function array(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
