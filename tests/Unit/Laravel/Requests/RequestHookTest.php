<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Requests;

use Ark4ne\ApiRevisions\Laravel\Requests\RequestHook;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class RequestHookTest extends TestCase
{
    public function test_the_hook_calls_its_closure_with_the_step_and_the_input(): void
    {
        $hook = new RequestHook(static fn(Revision $to, array $input): array => $input + ['to' => $to]);

        self::assertSame(['a' => 1, 'to' => ApiRevision::Atlas], $hook->upgrade(ApiRevision::Atlas, ['a' => 1]));
    }
}
