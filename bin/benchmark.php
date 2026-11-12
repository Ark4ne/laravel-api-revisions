<?php

declare(strict_types=1);

/*
 * The benchmark gate (docs/specs/epics.md US 5.4): renders 1 000 orders of the reference scenario at the head and at
 * the oldest revision through a `RevisionedResource` (US 8.5; a bare container holds the services the provider binds),
 * and with a plain `JsonResource` as baseline. Each projection is
 * compared with the baseline as a ratio (time and peak memory), so the gate holds on any machine; a ratio beyond the
 * reference by more than its tolerance fails. The scenarios are interleaved in each run and the gate reads the median of
 * the per-run ratios, so a drift of the machine hits both sides of a ratio. Refuses to run under Xdebug, which slows the
 * projection far more than the baseline: the reference is measured as CI runs it (PHP 8.4, no Xdebug).
 *
 * Usage: php bin/benchmark.php [--update]   (--update writes bin/benchmark/reference.json: review the diff)
 */

use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Laravel\Resources\EloquentSourceReader;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Customer;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Order;
use Test\Fixtures\Resources\PlainOrderResource;

require __DIR__ . '/../vendor/autoload.php';

if (function_exists('xdebug_info') && xdebug_info('mode') !== []) {
    fwrite(STDERR, 'Xdebug is active: run with XDEBUG_MODE=off (composer benchmark sets it).' . PHP_EOL);
    exit(1);
}

const ORDERS = 1000;
const REPEAT = 10;
const RUNS = 9;
const REFERENCE = __DIR__ . '/benchmark/reference.json';
const TOLERANCE = 0.10;

$orders = [];
for ($i = 0; $i < ORDERS; ++$i) {
    $orders[] = new Order(
        "Book {$i}",
        OrderStatus::Paid,
        1250 + $i,
        new DateTimeImmutable('2026-03-01 10:30:00+00:00'),
        new DateTimeImmutable('2026-03-04'),
        ['name' => 'Ada Lovelace', 'orders' => []],
        ['gift', 'fragile'],
        null,
        new Customer('Ada', 'Lovelace'),
    );
}

$shapes = new Shapes();
$request = Request::create('/orders');
$scope = new RevisionScope();
// what RevisionsServiceProvider binds, without booting an application
$container = new Container();
Container::setInstance($container);
$container->instance(RevisionsConfig::class, RevisionsConfig::fromArray(['enum' => ApiRevision::class]));
$container->instance(Shapes::class, $shapes);
$container->instance(Projector::class, new Projector(reader: new EloquentSourceReader(), shapes: $shapes));
$container->instance(RevisionScope::class, $scope);
$container->instance('request', $request);
RevisionScope::provider(static fn(): RevisionScope => $scope);

/**
 * A scenario renders every order and returns the outputs, so the memory measured holds the real work.
 *
 * @param list<Order> $orders
 *
 * @return Closure(): list<array<string, mixed>>
 */
$project = static fn(array $orders, Revision $revision): Closure => static fn(): array => ApiRevision::using($revision, static function () use ($orders, $request): array {
    $outputs = [];
    foreach ($orders as $order) {
        $outputs[] = (new PlainOrderResource($order))->resolve($request);
    }

    return $outputs;
});

$scenarios = [
    'baseline' => static function () use ($orders, $request): array {
        $outputs = [];
        foreach ($orders as $order) {
            $outputs[] = (new class ($order) extends JsonResource {
                /**
                 * @return array<string, mixed>
                 */
                public function toArray(Request $request): array
                {
                    $order = $this->resource;
                    assert($order instanceof Order);

                    return [
                        'title' => $order->title,
                        'status' => $order->status->value,
                        'total' => $order->total,
                        'placed_at' => $order->placed_at->format('Y-m-d\TH:i:sP'),
                        'delivery' => ['day' => $order->day->format('Y-m-d')],
                        'customer' => ['name' => $order->customer['name'], 'orders' => []],
                        'tags' => $order->tags,
                        'notes' => $order->notes,
                    ];
                }
            })->resolve($request);
        }

        return $outputs;
    },
    'head' => $project($orders, ApiRevision::Atlas),
    'oldest' => $project($orders, ApiRevision::Genesis),
];

// warm up: autoloading, compiled shapes
foreach ($scenarios as $scenario) {
    $scenario();
}

/**
 * @return float the time (seconds) of `REPEAT` renders of every order
 */
$time = static function (Closure $scenario): float {
    $start = hrtime(true);
    for ($i = 0; $i < REPEAT; ++$i) {
        $scenario();
    }

    return (hrtime(true) - $start) / 1e9;
};

/**
 * @return int the peak memory (bytes) of one render of every order, its outputs held
 */
$memory = static function (Closure $scenario): int {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $outputs = $scenario();
    $peak = memory_get_peak_usage() - $before;
    unset($outputs);

    return max(1, $peak);
};

/**
 * @param list<float> $values
 */
function median(array $values): float
{
    sort($values);

    return $values[intdiv(count($values), 2)] ?? 0.0;
}

// interleaved: each run measures every scenario, its ratios read against the baseline of the same run
$times = array_fill_keys(array_keys($scenarios), []);
$timeRatios = ['head' => [], 'oldest' => []];
for ($run = 0; $run < RUNS; ++$run) {
    $measured = array_map($time, $scenarios);
    foreach ($measured as $name => $seconds) {
        $times[$name][] = $seconds;
    }
    foreach (array_keys($timeRatios) as $name) {
        $timeRatios[$name][] = $measured[$name] / $measured['baseline'];
    }
}
$memories = array_map($memory, $scenarios);
$ratios = [];
foreach ($timeRatios as $name => $values) {
    $ratios[$name] = ['time' => round(median($values), 3), 'memory' => round($memories[$name] / $memories['baseline'], 3)];
}
$results = [];
foreach ($scenarios as $name => $scenario) {
    $results[$name] = [median($times[$name]) / REPEAT, $memories[$name]];
}

printf("%-9s %12s %12s %10s %10s\n", 'scenario', 'time (ms)', 'memory (KB)', 'time ×', 'memory ×');
foreach ($results as $name => [$time, $memory]) {
    printf(
        "%-9s %12.2f %12.1f %10s %10s\n",
        $name,
        $time * 1000,
        $memory / 1024,
        isset($ratios[$name]) ? number_format($ratios[$name]['time'], 2) : '1.00',
        isset($ratios[$name]) ? number_format($ratios[$name]['memory'], 2) : '1.00',
    );
}

$json = is_file(REFERENCE) ? file_get_contents(REFERENCE) : false;
$reference = $json === false ? null : json_decode($json, true);

$arguments = $_SERVER['argv'] ?? [];
if (is_array($arguments) && in_array('--update', $arguments, true)) {
    // a tolerance edited by hand is kept
    $tolerance = is_array($reference) && is_numeric($reference['tolerance'] ?? null) ? (float) $reference['tolerance'] : TOLERANCE;
    $written = file_put_contents(REFERENCE, json_encode(['tolerance' => $tolerance, 'ratios' => $ratios], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
    if ($written === false) {
        fwrite(STDERR, 'Cannot write bin/benchmark/reference.json.' . PHP_EOL);
        exit(1);
    }
    echo 'Reference written to bin/benchmark/reference.json: review the diff.' . PHP_EOL;
    exit(0);
}

if (!is_array($reference) || !is_array($reference['ratios'] ?? null) || !is_numeric($reference['tolerance'] ?? null)) {
    fwrite(STDERR, 'No valid reference: run php bin/benchmark.php --update.' . PHP_EOL);
    exit(1);
}

$tolerance = (float) $reference['tolerance'];
$failures = [];
foreach ($ratios as $name => $measured) {
    foreach ($measured as $metric => $ratio) {
        $references = $reference['ratios'][$name] ?? null;
        $expected = is_array($references) ? $references[$metric] ?? null : null;
        if (!is_numeric($expected)) {
            $failures[] = "{$name} {$metric}: missing from the reference";
        } elseif ($ratio > (float) $expected * (1 + $tolerance)) {
            $failures[] = sprintf('%s %s: ×%.2f, reference ×%.2f (+%d %% allowed)', $name, $metric, $ratio, $expected, $tolerance * 100);
        }
    }
}
foreach (array_keys($reference['ratios']) as $name) {
    if (!isset($ratios[$name])) {
        $failures[] = "{$name}: in the reference, not measured";
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'Regression:' . PHP_EOL . '  ' . implode(PHP_EOL . '  ', $failures) . PHP_EOL);
    exit(1);
}

echo 'No regression.' . PHP_EOL;
