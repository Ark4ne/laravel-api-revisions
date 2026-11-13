<?php

declare(strict_types=1);

namespace Test\Unit\Support;

use Ark4ne\ApiRevisions\Support\Diagnostic;
use Ark4ne\ApiRevisions\Support\Severity;
use Test\TestCase;

final class DiagnosticTest extends TestCase
{
    public function test_a_diagnostic_names_its_class_field_and_revision(): void
    {
        $diagnostic = new Diagnostic('name-collision', Severity::Error, 'App\\OrderResource', 'status', 'spring-26', 'Two fields named `status`.');

        self::assertSame('name-collision', $diagnostic->code);
        self::assertSame(Severity::Error, $diagnostic->severity);
        self::assertSame('App\\OrderResource', $diagnostic->class);
        self::assertSame('status', $diagnostic->field);
        self::assertSame('spring-26', $diagnostic->revision);
        self::assertSame('Two fields named `status`.', $diagnostic->message);
    }

    public function test_only_an_error_severity_makes_an_error(): void
    {
        self::assertTrue((new Diagnostic('a', Severity::Error, 'C', null, null, 'm'))->isError());
        self::assertFalse((new Diagnostic('a', Severity::Warning, 'C', null, null, 'm'))->isError());
    }

    public function test_a_diagnostic_is_json_ready(): void
    {
        self::assertSame(
            ['code' => 'bound-repeated', 'severity' => 'warning', 'class' => 'C', 'field' => null, 'revision' => 'atlas', 'message' => 'm'],
            (new Diagnostic('bound-repeated', Severity::Warning, 'C', null, 'atlas', 'm'))->toArray(),
        );
    }
}
