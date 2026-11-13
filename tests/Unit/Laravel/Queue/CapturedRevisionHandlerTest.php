<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Queue;

use Ark4ne\ApiRevisions\Laravel\Queue\CapturedRevisionHandler;
use Illuminate\Queue\CallQueuedHandler;
use LogicException;
use stdClass;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Jobs\CapturingJob;
use Test\Fixtures\Jobs\PlainJob;
use Test\TestCase;

final class CapturedRevisionHandlerTest extends TestCase
{
    public function test_a_job_marked_stores_the_backed_value_of_the_revision_and_names_this_handler(): void
    {
        $payload = ['job' => CallQueuedHandler::class . '@call', 'data' => ['command' => new CapturingJob()]];

        self::assertSame(
            [CapturedRevisionHandler::KEY => 'winter-25', 'job' => CapturedRevisionHandler::class . '@call'],
            CapturedRevisionHandler::capture($payload, static fn(): ApiRevision => ApiRevision::Winter25),
        );
    }

    public function test_a_job_not_marked_adds_nothing(): void
    {
        $payload = ['job' => CallQueuedHandler::class . '@call', 'data' => ['command' => new PlainJob()]];

        self::assertSame([], CapturedRevisionHandler::capture($payload, static fn(): never => throw new LogicException('The revision is read only for a job marked.')));
    }

    public function test_a_job_of_another_handler_adds_nothing(): void
    {
        $payload = ['job' => stdClass::class . '@call', 'data' => ['command' => new CapturingJob()]];

        self::assertSame([], CapturedRevisionHandler::capture($payload, static fn(): ApiRevision => ApiRevision::Winter25));
    }

    public function test_a_payload_without_command_adds_nothing(): void
    {
        self::assertSame([], CapturedRevisionHandler::capture(['job' => CallQueuedHandler::class . '@call', 'data' => []], static fn(): ApiRevision => ApiRevision::Winter25));
        self::assertSame([], CapturedRevisionHandler::capture(['job' => 'SomeHandler', 'data' => 'raw'], static fn(): ApiRevision => ApiRevision::Winter25));
    }
}
