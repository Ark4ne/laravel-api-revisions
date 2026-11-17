<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Laravel\RevisionsServiceProvider;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Support\Diagnostic;
use Illuminate\Contracts\Console\Kernel;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Checks\SecondCheck;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\Declarations\PodcastDeclaration;
use Test\Fixtures\ListedClasses;
use Test\TemporaryDirectory;

/**
 * The checker the provider binds, on a subset of the classes: the built-in and tagged checks
 * run, and the lock mismatch compares the classes given but does not report the others gone.
 */
final class CheckerForClassesTest extends FeatureTestCase
{
    use TemporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.discovery', ['paths' => [], 'classes' => [CheckedDeclaration::class, PodcastDeclaration::class]]);
        $config->set('revisions.lock_path', $this->temporary . '/lock');
        mkdir($this->temporary);
        CheckedDeclaration::$describe = static fn(): array => [
            'total' => Schema::string()->was(ApiRevision::Winter25, schema: Schema::int()),
        ];
    }

    protected function tearDown(): void
    {
        CheckedDeclaration::reset();

        parent::tearDown();
    }

    public function test_a_checker_for_some_classes_does_not_report_the_others_gone(): void
    {
        self::assertSame(0, $this->application()->make(Kernel::class)->call('revision:lock'));
        $this->application()->tag([SecondCheck::class], RevisionsServiceProvider::CHECKS);
        // the application now discovers one class of the two locked: the whole check reports the other one gone
        $this->application()->instance(ClassFinder::class, new ListedClasses([CheckedDeclaration::class]));
        $checker = $this->application()->make(Checker::class);

        self::assertSame(['conversion-lossy', 'lock-mismatch', 'second'], self::codes($checker));
        self::assertSame(['conversion-lossy', 'second'], self::codes($checker->forClasses(new ListedClasses([CheckedDeclaration::class]))));
    }

    public function test_a_checker_for_some_classes_still_compares_them_with_the_lock(): void
    {
        self::assertSame(0, $this->application()->make(Kernel::class)->call('revision:lock'));
        // a field added without since(): it leaks into the locked revisions
        CheckedDeclaration::$describe = static fn(): array => [
            'total' => Schema::string()->was(ApiRevision::Winter25, schema: Schema::int()),
            'notes' => Schema::string(),
        ];
        $checker = $this->application()->make(Checker::class)->forClasses(new ListedClasses([CheckedDeclaration::class]));

        self::assertSame(['conversion-lossy', 'lock-mismatch'], self::codes($checker));
    }

    /**
     * @return list<string>
     */
    private static function codes(Checker $checker): array
    {
        $codes = array_unique(array_map(static fn(Diagnostic $diagnostic): string => $diagnostic->code, $checker->run()->all()));
        sort($codes);

        return $codes;
    }
}
