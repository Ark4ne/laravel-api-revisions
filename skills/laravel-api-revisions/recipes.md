# Recipes

Every PHP snippet below is taken from a file the package's test suite runs, in the package repository (the comment
above it names the file under `tests/`, which is not installed with the package). The examples share one revision enum and one `Order`, with `spring-26` as the head. Rules and terms:
[SKILL.md](SKILL.md); every signature: [reference.md](reference.md).

- [Add a revision](#add-a-revision)
- [Add a field](#add-a-field)
- [Rename a field](#rename-a-field)
- [Change the type of a field](#change-the-type-of-a-field)
- [Remove or split a field](#remove-or-split-a-field)
- [Change a nested field](#change-a-nested-field)
- [Read the head in a controller](#read-the-head-in-a-controller)
- [Pin the revision of an account](#pin-the-revision-of-an-account)
- [Send a webhook](#send-a-webhook)
- [Test the revisions](#test-the-revisions)
- [Retire the oldest revision](#retire-the-oldest-revision)

## Add a revision

Append the case at the end of `order()`; the last case is the head.

<!-- tested: Fixtures/Recipes/ApiRevision.php -->
```php
enum ApiRevision: string implements Revision
{
    use IsRevision;

    #[Meta('First published revision', publishedAt: '2025-06-01')]
    case Genesis = 'genesis';

    #[Meta('`paid` becomes `state`, totals become decimal strings', publishedAt: '2025-12-01', deprecatedAt: '2026-11-01')]
    case Winter25 = 'winter-25';

    #[Meta('`state` renamed `status`, totals in cents, `name` split, tags')]
    case Spring26 = 'spring-26';

    public static function order(): array
    {
        return [self::Genesis, self::Winter25, self::Spring26];
    }
}
```

Then, **before** changing any declaration, lock the previous head, now that it is no longer the head:

```bash
php artisan revision:lock      # locks every revision but the head that has no lock yet
```

The fields `spring-26` changes then name `spring-26` (`since`) or `winter-25`, its `previous()` (`was`, `until`).

## Add a field

`since(N)`, alone or for a group spread with `...`. In a response, older clients do not get the field.

<!-- tested: Fixtures/Recipes/Http/Resources/OrderResource.php -->
```php
...Schema::since(ApiRevision::Spring26, [
    'first_name' => Schema::string(),
    'last_name' => Schema::string(),
    'tags' => Schema::list(Schema::string()),
]),
```

In a request, older clients never send it: give `default:` for what the controller receives instead. A `required()`
field must have one (`required-since-without-default`).

<!-- tested: Fixtures/Recipes/Http/Requests/StoreOrderRequest.php -->
```php
'tags' => Schema::list(Schema::string())->since(ApiRevision::Spring26, default: []),

...Schema::since(ApiRevision::Spring26, [
    'first_name' => Schema::string()->required(),
    'last_name' => Schema::string()->required(),
], default: null),
```

## Rename a field

`was(N->previous(), name: 'old')`. The head key stays the source: the field is read from `status` on the model and
rendered as `state` for `winter-25` clients. A request receives `state` from them and the controller reads `status`.

<!-- tested: Fixtures/Recipes/Http/Resources/OrderResource.php -->
```php
'status' => Schema::enum(OrderStatus::class)
    ->was(ApiRevision::Winter25, name: 'state')
```

A query parameter is renamed the same way, in `queryParameters()`:

<!-- tested: Fixtures/Recipes/Http/Requests/StoreOrderRequest.php -->
```php
'notify' => Schema::bool()->was(ApiRevision::Winter25, name: 'send_email'),
```

## Change the type of a field

`was(R, schema: ...)`, with `down:` (responses) and `up:` (requests) when the automatic cast is not what clients need
or does not exist. **`down:` receives the PHP value of the newer shape**: here an `OrderStatus` case, not `'paid'`,
because `winter-25` still had the enum (only renamed). It returns the PHP value of the older shape.

<!-- tested: Fixtures/Recipes/Http/Resources/OrderResource.php -->
```php
'status' => Schema::enum(OrderStatus::class)
    ->was(ApiRevision::Winter25, name: 'state')
    ->was(
        ApiRevision::Genesis,
        name: 'paid',
        schema: Schema::bool(),
        down: fn(OrderStatus $status): bool => $status === OrderStatus::Paid,
    ),
```

Chained `was()` are deltas, newest first: the `genesis` closure receives what the `winter-25` one returned, a decimal
`string`, not the `int` of the head.

<!-- tested: Fixtures/Recipes/Http/Resources/OrderResource.php -->
```php
'total' => Schema::int('total_cents')
    ->was(
        ApiRevision::Winter25,
        schema: Schema::string(),
        down: fn(int $cents): string => number_format($cents / 100, 2, '.', ''),
    )
    ->was(
        ApiRevision::Genesis,
        schema: Schema::float(),
        down: fn(string $amount): float => (float) $amount,
    ),
```

`up:` goes the other way: it receives the typed value of the older shape (a `bool`, a `string`) and returns the newer
one. `float` → `string` from `genesis` is an automatic, lossless cast: no closure.

<!-- tested: Fixtures/Recipes/Http/Requests/StoreOrderRequest.php -->
```php
'status' => Schema::enum(OrderStatus::class)
    ->was(ApiRevision::Winter25, name: 'state')
    ->was(
        ApiRevision::Genesis,
        name: 'paid',
        schema: Schema::bool(),
        up: fn(bool $paid): OrderStatus => $paid ? OrderStatus::Paid : OrderStatus::Pending,
    ),
'total' => Schema::int()->required()->min(0)
    ->was(
        ApiRevision::Winter25,
        schema: Schema::string(),
        up: fn(string $amount): int => (int) round(((float) $amount) * 100),
    )
    ->was(ApiRevision::Genesis, schema: Schema::float()),
```

## Remove or split a field

`until(N->previous())` keeps the old field, with its own source, for the clients that still have it. Here `spring-26`
replaces `name` by `first_name` and `last_name`.

<!-- tested: Fixtures/Recipes/Http/Resources/OrderResource.php -->
```php
...Schema::until(ApiRevision::Winter25, [
    'name' => Schema::string(fn($value, Order $order): string => "{$order->first_name} {$order->last_name}"),
]),
```

In a request, what a `was()` cannot express goes to `upgrade()`, called once per step with the input in the shape of
`$to`, before the defaults of the fields added in `$to`. The retired field is dropped after it.

<!-- tested: Fixtures/Recipes/Http/Requests/StoreOrderRequest.php -->
```php
...Schema::until(ApiRevision::Winter25, [
    'name' => Schema::string()->required(),
]),
```

<!-- tested: Fixtures/Recipes/Http/Requests/StoreOrderRequest.php -->
```php
protected function upgrade(Revision $to, array $input): array
{
    if ($to === ApiRevision::Spring26 && is_string($input['name'] ?? null)) {
        [$input['first_name'], $input['last_name']] = explode(' ', $input['name'], 2) + [1 => ''];
    }

    return $input;
}
```

## Change a nested field

Children of `Schema::object()` and items of `Schema::list()` carry their own history.

<!-- tested: Fixtures/Recipes/Http/Resources/OrderResource.php -->
```php
'lines' => Schema::list(Schema::object([
    'sku' => Schema::string(),
    'quantity' => Schema::int()->was(ApiRevision::Genesis, name: 'qty'),
])),
'customer' => Schema::resource(CustomerResource::class)->whenLoaded('customer'),
```

A nested resource (`Schema::resource()`) is projected at the same revision with its own declarations. Its condition
goes on the descriptor (`->whenLoaded()`), never `$this->whenLoaded()`.

## Read the head in a controller

`validated()` (or `safe()`) returns the head, whatever the client's revision. A behaviour change with no schema impact
tests the current revision.

<!-- tested: Fixtures/Recipes/Http/Controllers/OrderController.php -->
```php
public function store(StoreOrderRequest $request): JsonResponse
{
    $input = $request->validated();
    $order = new Order([
        'title' => $input['title'],
        'status' => $input['status'] ?? 'pending',
        'total_cents' => $input['total'],
        'first_name' => $input['first_name'],
        'last_name' => $input['last_name'],
        'tags' => $input['tags'] ?? [],
        'lines' => [],
    ]);

    return OrderResource::make($order)
        ->response()
        ->setStatusCode(ApiRevision::current()->isAtMost(ApiRevision::Winter25) ? 200 : 201);
}
```

## Pin the revision of an account

A custom resolver, first in the chain of `config/revisions.php`; `null` lets the next one (the header) answer.

<!-- tested: Fixtures/Recipes/Http/Resolvers/AccountPinnedResolver.php -->
```php
final readonly class AccountPinnedResolver implements RevisionResolver
{
    public function resolve(Request $request): ?Revision
    {
        $account = $request->user();

        return $account instanceof Account ? $account->api_revision : null;
    }
}
```

<!-- tested: Fixtures/Recipes/config/revisions.php -->
```php
'resolvers' => [
    AccountPinnedResolver::class => [],
    HeaderResolver::class => ['name' => 'Api-Revision'],
],
```

## Send a webhook

Choose the revision and render **inside** `using()`. A queued job implementing `CapturesRevision` runs in the revision
of its dispatch: dispatch it in a block body, never from an arrow function.

<!-- tested: Fixtures/Recipes/Webhooks/OrderWebhooks.php -->
```php
public function sendNow(Account $account, Order $order): void
{
    ApiRevision::using($account->api_revision ?? ApiRevision::latest(), function () use ($account, $order): void {
        Http::post($account->webhook_url, ['type' => 'order.updated', 'data' => OrderResource::make($order)->resolve()]);
    });
}

public function queue(Account $account, Order $order): void
{
    ApiRevision::using($account->api_revision ?? ApiRevision::latest(), function () use ($account, $order): void {
        SendOrderWebhook::dispatch($account->webhook_url, $order);
    });
}
```

<!-- tested: Fixtures/Recipes/Jobs/SendOrderWebhook.php -->
```php
final class SendOrderWebhook implements ShouldQueue, CapturesRevision
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly string $url, public readonly Order $order) {}

    public function handle(): void
    {
        Http::post($this->url, ['type' => 'order.updated', 'data' => OrderResource::make($this->order)->resolve()]);
    }
}
```

## Test the revisions

With `InteractsWithRevisions` on the test case: send a request in a revision and validate the response against the
schema of that revision (objects closed: a leaked field fails), and render a fixture at every revision.

<!-- tested: Feature/RecipesTest.php -->
```php
$this->withRevision(ApiRevision::Winter25)
    ->getJson('/orders/1')
    ->assertOk()
    ->assertMatchesRevisionSchema(OrderResource::class);

$this->assertRendersAtEveryRevision(OrderResource::class, self::order());
```

`$this->assertRevisionsAreConsistent()` runs `revision:check` in PHPUnit. The schema assertions need
`opis/json-schema` (dev).

## Retire the oldest revision

When no client uses the oldest revision `P` any more:

```bash
php artisan revision:prune                       # lists each until(P), was(P), since(P), with file and line
# delete them, then the case P from the enum and from order() (PHPStan reports any reference left)
rm revisions.lock/genesis.json
php artisan revision:lock --revision=winter-25   # the new oldest: its recorded predecessor is gone
php artisan revision:check
```
