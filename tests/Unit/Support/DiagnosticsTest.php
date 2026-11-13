<?php

declare(strict_types=1);

namespace Test\Unit\Support;

use Ark4ne\ApiRevisions\Support\Diagnostic;
use Ark4ne\ApiRevisions\Support\Diagnostics;
use Ark4ne\ApiRevisions\Support\Severity;
use Test\TestCase;

final class DiagnosticsTest extends TestCase
{
    public function test_diagnostics_are_kept_in_the_order_they_are_added(): void
    {
        $diagnostics = new Diagnostics();
        $warning = self::diagnostic(Severity::Warning);
        $error = self::diagnostic(Severity::Error);

        $diagnostics->add($warning);
        $diagnostics->add($error);

        self::assertSame([$warning, $error], $diagnostics->all());
        self::assertSame([$warning, $error], iterator_to_array($diagnostics));
        self::assertCount(2, $diagnostics);
    }

    public function test_errors_and_warnings_are_listed_apart(): void
    {
        $diagnostics = new Diagnostics();
        $first = self::diagnostic(Severity::Error);
        $warning = self::diagnostic(Severity::Warning);
        $second = self::diagnostic(Severity::Error);
        foreach ([$first, $warning, $second] as $diagnostic) {
            $diagnostics->add($diagnostic);
        }

        self::assertSame([$first, $second], $diagnostics->errors());
        self::assertSame([$warning], $diagnostics->warnings());
    }

    public function test_only_an_error_fails_the_check(): void
    {
        $diagnostics = new Diagnostics();
        self::assertFalse($diagnostics->hasErrors());

        $diagnostics->add(self::diagnostic(Severity::Warning));
        self::assertFalse($diagnostics->hasErrors());

        $diagnostics->add(self::diagnostic(Severity::Error));
        self::assertTrue($diagnostics->hasErrors());
    }

    private static function diagnostic(Severity $severity): Diagnostic
    {
        return new Diagnostic('code', $severity, 'C', null, null, 'message');
    }
}
