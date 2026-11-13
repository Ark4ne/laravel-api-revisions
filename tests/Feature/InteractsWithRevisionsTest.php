<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Testing\InteractsWithRevisions;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Checked\FaultyDeclaration;
use Test\Fixtures\Checked\WarnedDeclaration;

final class InteractsWithRevisionsTest extends FeatureTestCase
{
    use InteractsWithRevisions;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.discovery', ['paths' => [dirname(__DIR__) . '/Fixtures/Checked'], 'classes' => []]);
    }

    public function test_the_assertion_fails_with_the_diagnostics(): void
    {
        try {
            $this->assertRevisionsAreConsistent();
        } catch (AssertionFailedError $failure) {
            // PHPUnit appends its own "Failed asserting that true is false."
            self::assertStringStartsWith(
                implode("\n", [
                    'The revisions are inconsistent (2 errors, 1 warning):',
                    "[error] history-before-since " . FaultyDeclaration::class . " 'total' at genesis: The was(Genesis) of 'total' (request-body) is older than its since(Winter25): it describes no revision.",
                    "[error] required-since-without-default " . FaultyDeclaration::class . " 'priority' at atlas: 'priority' (request-body) is required since Atlas without default: older clients never send it; give since(Atlas, default: ...).",
                    "[warning] bound-repeated " . WarnedDeclaration::class . " 'title' at genesis: since() is called more than once on 'title' (response): the last call replaces Genesis.",
                ]),
                $failure->getMessage(),
            );

            return;
        }

        self::fail('The assertion passed.');
    }

    public function test_the_assertion_needs_the_application(): void
    {
        $app = $this->app;
        $this->app = null;

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('assertRevisionsAreConsistent() needs the application: call it in a test, after setUp().');

            $this->assertRevisionsAreConsistent();
        } finally {
            $this->app = $app;
        }
    }

    public function test_the_assertion_passes_with_warnings_only(): void
    {
        $this->application()['config']->set('revisions.discovery.paths', [dirname(__DIR__) . '/Fixtures/Checked/WarnedDeclaration.php']);

        // passes: one assertion counted, nothing thrown
        $this->assertRevisionsAreConsistent();
    }
}
