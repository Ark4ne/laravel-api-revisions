# laravel-api-revisions

Typed schemas and their evolution through ordered API revisions, Stripe-style, for Laravel.

Your code declares the **latest** shape of a response or a request: the **head**. Older shapes are declared only on
the fields that changed (`since()`, `until()`, `was()`). From that one declaration the library derives:

- the response of every revision;
- the validation of every request in the client's revision, and its upgrade to the head;
- a JSON Schema per revision, a changelog, a consistency check and a lock of the published revisions.

```php
'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
```

Clients up to `winter-25` receive and send `state`, later clients `status`. Your controllers only ever see `status`.

Requirements: PHP 8.3+, Laravel 12.46+ or 13. The [guide](docs/guide.md) covers every feature in depth.

## Install

```bash
composer require ark4ne/laravel-api-revisions
php artisan vendor:publish --tag=revisions-config
```

The service provider is auto-discovered. Set `enum` in `config/revisions.php` to your revision enum (below).

## The revision enum

A revision is a case of a backed enum. The backed value is what clients send and receive. `order()` lists every case,
oldest first: a new revision is appended, and its last case is the **head**.

```php
use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Meta;
use Ark4ne\ApiRevisions\Revisions\Revision;

enum ApiRevision: string implements Revision
{
    use IsRevision;

    #[Meta('First published revision', publishedAt: '2025-06-01')]
    case Genesis = 'genesis';

    #[Meta('Totals become decimal strings', publishedAt: '2025-12-01', deprecatedAt: '2026-11-01', sunsetAt: '2027-05-01')]
    case Winter25 = 'winter-25';

    #[Meta('Totals in cents, `state` renamed `status`')]
    case Spring26 = 'spring-26';

    public static function order(): array
    {
        return [self::Genesis, self::Winter25, self::Spring26];
    }
}
```

`ApiRevision::current()` is the revision of the running request. `isAtMost()`, `isBefore()`, `previous()` and the
other `IsRevision` methods compare cases by their place in `order()`.

## A resource

A `JsonResource` declares the head with `Schema` descriptors. Each descriptor is lazy: it names its source (the key it
is declared under by default, another key, a dotted path or a closure `($value, $subject)`), never an evaluated value.

```php
use Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;

final class OrderResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    public function toArray(Request $request): array
    {
        return [
            'title' => Schema::string(),
            'status' => Schema::enum(OrderStatus::class)
                ->was(ApiRevision::Winter25, name: 'state'),
            'total' => Schema::int(fn ($value, Order $order) => $order->total_cents)
                ->was(ApiRevision::Winter25, schema: Schema::string(),
                    down: fn (int $cents): string => number_format($cents / 100, 2, '.', ''))
                ->was(ApiRevision::Genesis, schema: Schema::float(),
                    down: fn (string $amount): float => (float) $amount),
            'customer' => Schema::resource(CustomerResource::class)->whenLoaded('customer'),

            ...Schema::since(ApiRevision::Spring26, [
                'tags' => Schema::list(Schema::string()),
            ]),
        ];
    }
}
```

| Client | `status` key | `total` | `tags` |
| --- | --- | --- | --- |
| `spring-26` (head) | `status` | `1250` | present |
| `winter-25` | `state` | `"12.50"` | absent |
| `genesis` | `state` | `12.5` | absent |

Every revision named is **inclusive**: `since(R)` from `R` included, `until(R)` and `was(R)` up to `R` included. When a
field changes in a new revision `N`, write `was(N->previous())`; when it is removed, `until(N->previous())`; when it is
added, `since(N)`. A `was()` is a delta from the next newer shape. Most scalar changes convert automatically, as PHP
casts (`revision:check` warns on the ones that may lose information): give `down:` / `up:` when the cast is not what
your clients need.

## A request

A `FormRequest` declares the head input in `body()` (and the query in `queryParameters()`). The input is validated in
the client's revision, with the names the client knows (`state` for a `winter-25` client), then upgraded step by step
to the head.

```php
use Ark4ne\ApiRevisions\Laravel\Requests\RevisionedRequest;

final class StoreOrderRequest extends FormRequest implements Revisioned
{
    use RevisionedRequest;

    protected function body(): array
    {
        return [
            'title' => Schema::string()->required()->rules('max:255'),
            'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
            'total' => Schema::int()->min(0)
                ->was(ApiRevision::Winter25, schema: Schema::string(),
                    up: fn (string $amount): int => (int) round(((float) $amount) * 100))
                ->was(ApiRevision::Genesis, schema: Schema::float()),
            'priority' => Schema::int()->required()->since(ApiRevision::Spring26, default: 0),
        ];
    }
}
```

Read the head from `$request->validated()` or `$request->safe()`, never from `input()`: `input()` still holds what the
client sent. A `required()` field added later declares the `default:` older clients get.

## Resolving the revision

The middleware alias `revision` (`ResolveRevision`) resolves the revision of each request: put it on your API routes.

```php
Route::middleware('revision')->group(function () {
    Route::get('/orders/{order}', [OrderController::class, 'show']);
});
```

`resolvers` in `config/revisions.php` is a chain: the first resolver that finds a revision wins. The header resolver
(`Api-Revision`) is the default. `PathResolver` (with `Route::revisioned()`) and `QueryResolver` are built in. A custom
`RevisionResolver` can pin the revision of an account. When no resolver answers, `default` applies (`latest` by default).
An unknown revision is answered with **400**. Each response carries the `Api-Revision` header, plus `Deprecation` and
`Sunset` from the revision's `#[Meta]`.

## Check and lock in CI

```bash
php artisan revision:check    # fails on any inconsistent declaration, or on a change to a locked revision
php artisan revision:lock     # locks every revision but the head (commit the lock directory)
```

`revision:check` finds every class implementing `Revisioned` under `discovery.paths`, plus `discovery.classes`. It reports dead or contradictory
declarations, missing conversions, required fields added without default, and any difference with the lock. The lock
is the safety net against a **forgotten** `since()`: a new field leaking into a published revision fails the build. The head
stays free: run `revision:lock` when you add a new revision (it locks the previous head), and `revision:check` in CI. In PHPUnit,
`assertRevisionsAreConsistent()` does the same check.

Also available: `revision:export` (the JSON Schema of every class at every revision), `revision:changelog` (Markdown),
`revision:prune` ([below](#retiring-a-revision)).

## Retiring a revision

1. `php artisan revision:prune` lists the declarations that serve only the oldest revision, with their file and line:
   its `until()` fields, `was()` and `since()`. Delete them.
2. Delete the case from the enum and from `order()`. PHPStan points at any reference left.
3. Delete its lock file (`revisions.lock/<value>.json`). Re-lock the new oldest revision, whose recorded predecessor is
   gone: `php artisan revision:lock --revision=<value>`.
4. `php artisan revision:check`.

## Outside HTTP

Webhooks, jobs and notifications have no request: target a revision explicitly, and render inside the closure.

```php
ApiRevision::using($account->api_revision, function () use ($account, $order) {
    Http::post($account->webhook_url, OrderResource::make($order)->resolve());
});
```

A queued job implementing the marker `Laravel\Queue\CapturesRevision` runs in the revision current when it was
dispatched. Dispatch it inside a block body, never from an arrow function returning the `PendingDispatch`.

## Testing

```php
use Ark4ne\ApiRevisions\Testing\InteractsWithRevisions;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithRevisions;
}

$this->withRevision(ApiRevision::Winter25)
    ->getJson('/orders/1')
    ->assertOk()
    ->assertMatchesRevisionSchema(OrderResource::class);

$this->assertRendersAtEveryRevision(OrderResource::class, Order::factory()->make());
```

The schema assertions need `opis/json-schema` (`composer require --dev opis/json-schema`).

## PHPStan

The package ships `extension.neon`, included by `phpstan/extension-installer` (or by hand with
`includes: [vendor/ark4ne/laravel-api-revisions/extension.neon]`). It types `Route::revisioned()`,
`assertMatchesRevisionSchema()` and the custom types registered on `Schema` (`TypeRegistry::register('money',
MoneySchema::class)`, [guide](docs/guide.md#custom-types)), once each name is mapped to its class:

```neon
parameters:
    apiRevisions:
        types:
            money: App\Schema\MoneySchema
```

## Octane and caching

`php artisan optimize` runs `revision:cache`, which compiles every shape ahead (`optimize:clear` removes it). Under
Octane, add `Ark4ne\ApiRevisions\Laravel\RevisionsConfig` and `Ark4ne\ApiRevisions\Compilation\Shapes` to `warm`
in `config/octane.php`, so they are built once per worker.

## License

MIT.
