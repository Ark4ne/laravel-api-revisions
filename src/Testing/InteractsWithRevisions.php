<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Testing;

use Ark4ne\ApiRevisions\Checking\Checker;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * Helpers for the `TestCase` of an application (model.md D28), as Laravel's `InteractsWithDatabase`: used on a Laravel
 * test case, whose `$app` holds the application.
 *
 * @property \Illuminate\Contracts\Foundation\Application|null $app
 */
trait InteractsWithRevisions
{
    /**
     * Fails with every diagnostic of the check (`revision:check`) when one is an error; warnings alone pass.
     */
    public function assertRevisionsAreConsistent(): void
    {
        $app = $this->app ?? throw new LogicException('assertRevisionsAreConsistent() needs the application: call it in a test, after setUp().');
        $diagnostics = $app->make(Checker::class)->run();
        $errors = count($diagnostics->errors());
        $lines = [sprintf(
            'The revisions are inconsistent (%d %s, %d %s):',
            $errors,
            $errors === 1 ? 'error' : 'errors',
            count($diagnostics->warnings()),
            count($diagnostics->warnings()) === 1 ? 'warning' : 'warnings',
        )];
        foreach ($diagnostics as $diagnostic) {
            $lines[] = sprintf(
                '[%s] %s %s%s%s: %s',
                $diagnostic->severity->value,
                $diagnostic->code,
                $diagnostic->class,
                $diagnostic->field === null ? '' : " '{$diagnostic->field}'",
                $diagnostic->revision === null ? '' : " at {$diagnostic->revision}",
                $diagnostic->message,
            );
        }

        Assert::assertFalse($diagnostics->hasErrors(), implode("\n", $lines));
    }
}
