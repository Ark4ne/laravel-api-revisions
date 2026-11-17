<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Queue;

/**
 * Marks a queued job that runs in the revision of its dispatch: the revision current when the job
 * is pushed is stored in its payload, and the job (`handle()`, its middleware, `failed()`) runs inside `using()` of
 * it, wherever and whenever a worker picks it up. Opt-in: a job without it runs in the base revision of the worker
 * (the configured fixed default, else the head). A job sending a client a resource (a webhook, a notification it
 * sends) implements it; a job working on the head data only does not need it. Only a `ShouldQueue` job: a queued
 * notification, mailable, listener or broadcast is wrapped by Laravel and not captured.
 *
 * ```php
 * final class SendOrderWebhook implements ShouldQueue, CapturesRevision
 * ```
 *
 * Dispatch inside `using()`, never return the `PendingDispatch` out of it (it pushes when destroyed, after `using()`
 * has restored the outer revision): `using($r, function () use ($order) { SendOrderWebhook::dispatch($order); })`.
 */
interface CapturesRevision {}
