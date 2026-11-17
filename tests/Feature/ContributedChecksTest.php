<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Laravel\RevisionsServiceProvider;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Support\Diagnostic;
use Ark4ne\ApiRevisions\Testing\InteractsWithRevisions;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\AssertionFailedError;
use stdClass;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Checks\ReservedNameCheck;
use Test\Fixtures\Checks\ReservedNames;
use Test\Fixtures\Checks\SecondCheck;
use Test\Fixtures\Declarations\CheckedDeclaration;

/**
 * Checks a package contributes by tagging them `RevisionsServiceProvider::CHECKS`: run by
 * `revision:check` and `assertRevisionsAreConsistent()`, resolved through the container.
 */
final class ContributedChecksTest extends FeatureTestCase
{
    use InteractsWithRevisions;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.discovery', ['paths' => [], 'classes' => [CheckedDeclaration::class]]);
        CheckedDeclaration::$describe = static fn(): array => [
            'title' => Schema::string(),
            'id' => Schema::string()->since(ApiRevision::Atlas),
        ];
    }

    protected function tearDown(): void
    {
        CheckedDeclaration::reset();

        parent::tearDown();
    }

    public function test_a_tagged_check_fails_the_check_command(): void
    {
        $this->application()->tag([ReservedNameCheck::class], RevisionsServiceProvider::CHECKS);
        $kernel = $this->application()->make(Kernel::class);

        self::assertSame(1, $kernel->call('revision:check'));
        $output = $kernel->output();
        self::assertStringContainsString('reserved-name', $output);
        self::assertStringContainsString("'id' is reserved.", $output);
    }

    public function test_a_tagged_check_fails_the_consistency_assertion(): void
    {
        $this->application()->tag([ReservedNameCheck::class], RevisionsServiceProvider::CHECKS);

        try {
            $this->assertRevisionsAreConsistent();
            self::fail('The contributed check reports an error.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString("[error] reserved-name Test\\Fixtures\\Declarations\\CheckedDeclaration 'id' at atlas: 'id' is reserved.", $failure->getMessage());
        }
    }

    public function test_a_tagged_check_is_built_by_the_container(): void
    {
        $this->application()->instance(ReservedNames::class, new ReservedNames(['title']));
        $this->application()->tag([ReservedNameCheck::class], RevisionsServiceProvider::CHECKS);
        $kernel = $this->application()->make(Kernel::class);

        $kernel->call('revision:check');
        $output = $kernel->output();

        self::assertStringContainsString("'title' is reserved.", $output);
        self::assertStringNotContainsString("'id' is reserved.", $output);
    }

    public function test_tagged_checks_run_after_the_built_in_ones_in_tag_order(): void
    {
        CheckedDeclaration::$describe = static fn(): array => ['id' => Schema::string()->since(ApiRevision::Atlas)->since(ApiRevision::Atlas)];
        $this->application()->tag([ReservedNameCheck::class], RevisionsServiceProvider::CHECKS);
        $this->application()->tag([SecondCheck::class], RevisionsServiceProvider::CHECKS);

        $codes = array_map(static fn(Diagnostic $diagnostic): string => $diagnostic->code, $this->application()->make(Checker::class)->run()->all());

        self::assertSame(['bound-repeated', 'reserved-name', 'second'], $codes);
    }

    public function test_without_tagged_check_only_the_built_in_checks_run(): void
    {
        self::assertSame(0, $this->application()->make(Kernel::class)->call('revision:check'));
        $this->assertRevisionsAreConsistent();
    }

    public function test_a_tagged_service_that_is_not_a_check_is_refused(): void
    {
        $this->application()->tag([stdClass::class], RevisionsServiceProvider::CHECKS);

        $this->expectExceptionObject(ConfigurationException::notACheck(stdClass::class));

        $this->assertRevisionsAreConsistent();
    }
}
