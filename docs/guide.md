# Guide

This guide covers every feature of `ark4ne/laravel-api-revisions`. The [README](../README.md) is the short tour. The
decided behaviour, with the reasons behind it, is in [`docs/specs/model.md`](specs/model.md).

- [Concepts](#concepts)
- [The revision enum](#the-revision-enum)
- [Schema vocabulary](#schema-vocabulary)
- [Sources and subjects](#sources-and-subjects)
- [History: since, until, was](#history-since-until-was)
- [Conversions](#conversions)
- [Responses](#responses)
- [Requests](#requests)
- [Resolving the revision](#resolving-the-revision)
- [Outside HTTP](#outside-http)
- [Check](#check)
- [Lock](#lock)
- [Export, components and changelog](#export-components-and-changelog)
- [Retiring a revision](#retiring-a-revision)
- [Testing](#testing)
- [Custom types](#custom-types)
- [Performance and Octane](#performance-and-octane)
- [Configuration](#configuration)

## Concepts

- **Revision**: a case of your backed enum. Clients name it by its backed value.
- **Head**: the last revision of `order()`. Your code declares the head, and only the head.
- **Shape**: the fields of a class at one revision: their names, types and conversions. It is computed from the head
  declarations and their history, once per class and revision.
- **Down**: a response is projected from the head down to the client's revision.
- **Up**: a request is validated in the client's revision, then upgraded up to the head. Controllers only see the
  head.

## The revision enum

```php
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

- `order()` is explicit, oldest first. Append each new revision; never reorder it. `revision:check` verifies that it
  holds every case exactly once, and the lock that a published revision never moves.
- The enum may be string- or int-backed. Case names are free.
- `IsRevision` gives `isBefore()`, `isAfter()`, `isAtLeast()`, `isAtMost()`, `previous()`, `next()` (`null` past the
  ends), `latest()` (the head), `oldest()`, `current()`, `using()` and `meta()`.
- `#[Meta]` takes a description and the days `publishedAt`, `deprecatedAt`, `sunsetAt` (`Y-m-d`). It feeds the export,
  the changelog and the `Deprecation` / `Sunset` response headers.

A behaviour change with no schema impact stays in your code:

```php
if (ApiRevision::current()->isAtMost(ApiRevision::Winter25)) {
    // the old rounding
}
```

## Schema vocabulary

Every helper is a static method of `Ark4ne\ApiRevisions\Schema\Schema` and returns a typed, immutable descriptor.

| Helper | Specific modifiers |
| --- | --- |
| `Schema::string()` | `min()`, `max()`, `format()`, `pattern()` |
| `Schema::int()`, `Schema::float()` | `min()`, `max()` |
| `Schema::bool()` | — |
| `Schema::date()`, `Schema::dateTime()` | `format()` (default: `date_format` / `date_time_format`) |
| `Schema::enum(OrderStatus::class)` | a backed enum, its values exported |
| `Schema::list(Schema::string())` | `min()`, `max()` on the size |
| `Schema::object([...])` | nested fields; `component('Address')` exports it once as a `$ref` |
| `Schema::resource(CustomerResource::class)` | another `Revisioned` class, projected at the same revision |
| `Schema::rule(['array', new MetaRule()])` | escape hatch, `any` in the export |
| `Schema::money()` | a [custom type](#custom-types) |

Common modifiers: `nullable()`, `required()`, `sometimes()`, `describe()`, `example()`, `deprecated()`, `rules(...)`
(Laravel rules: strings, rule objects, closures), `when()`, `whenHas()`, `whenLoaded()`, `since()`, `until()`, `was()`.

A rule implementing `Schema\DescribesSchema` (`toSchema(): array`) adds its JSON Schema keywords to the export.

## Sources and subjects

The **subject** is what a declaration reads: the model of a `JsonResource`, each item of a list, the value of an
object's source. The **source** of a field says what to read on it. It is always the last argument of a helper.

| Declaration | Reads |
| --- | --- |
| `'title' => Schema::string()` | `title`, the key it is declared under (its head key, whatever the revision) |
| `Schema::string('ref')` | `ref` |
| `Schema::string('customer.name')` | a dotted path; `null` as soon as a segment is `null` |
| `Schema::string(fn ($value, Order $order) => $order->fullName())` | the closure |
| `Schema::object([...], 'shippingAddress')` | the children read the value of `shippingAddress` |
| `Schema::object([...])` | the children read the parent's subject |
| `Schema::list(Schema::object([...]), 'lines')` | each item is the subject of the object |

Every closure the library calls (a source, `when()`, `down:`, `up:`) receives `($value, $subject)`. It may declare
fewer parameters. In a source closure, `$value` is the value of the declared key when the subject holds it without side
effect (an existing offset, an initialized property, a loaded attribute or relation), `null` otherwise.

**A source is never an evaluated value.** The shapes are compiled without a real subject, and no source, closure or
condition runs then. A value that is not a key, a path or a closure (an int, a model) throws; a string is always read as
a key, so `Schema::string($this->title)` reads the key named by the title: write `Schema::string('title')` or a
closure.

Conditions:

- `->whenHas()` keeps the field only when the subject has the key, `->whenLoaded('customer')` only when the relation is
  loaded. Both run **before** the source and protect it: no lazy load, no missing attribute.
- `->when(fn ($value, $subject) => ...)` runs **after** the source, on the head value. A field whose condition fails is
  omitted, never `null`.
- A conditional field is optional in the export.

## History: since, until, was

| Declaration | Meaning |
| --- | --- |
| `->since(R)` | the field exists from `R` included |
| `->until(R)` | the field exists up to `R` included, and is gone in `R->next()` |
| `->was(R, name:, schema:, down:, up:)` | up to `R` included, the field had another name, another type, or both |

**Writing rule.** In a new revision `N`: a changed field gets `was(N->previous())`, a removed field
`until(N->previous())`, an added field `since(N)`. Bounds are inclusive so that retiring the oldest revision `P` makes
exactly its `was(P)` and `until(P)` dead.

**Chained deltas.** A `was()` describes the shape just before the next newer one. An argument left out keeps the value
of that newer shape:

```php
'total' => Schema::int()
    ->was(ApiRevision::Winter25, schema: Schema::string(),
        down: fn (int $cents): string => number_format($cents / 100, 2, '.', ''),
        up: fn (string $amount): int => (int) round(((float) $amount) * 100))
    ->was(ApiRevision::Genesis, schema: Schema::float()),   // keeps the name; string ↔ float is automatic
```

The automatic string → float of the response may lose information: `revision:check` warns (`conversion-lossy`) until
you give `down:`. A `was()` never has a source: an older value is always derived from the head value. When an older value needs more
than the head value, use the subject given to `down:`, or declare the old field with `until()` and its own source next
to the new one.

**Groups.** `Schema::since()` and `Schema::until()` apply a bound to several fields. They return an array to spread:

```php
...Schema::since(ApiRevision::Spring26, [
    'tags' => Schema::list(Schema::string()),
    'notes' => Schema::string()->nullable(),
], default: null),

...Schema::until(ApiRevision::Winter25, [
    'name' => Schema::string(fn () => $this->fullName()),
]),
```

A member may declare a newer `since()` or an earlier `until()` than its group, never an older or later one.

## Conversions

Most scalar type changes need no closure: the library casts as PHP does, in the pairs below. Give `down:` (responses)
or `up:` (requests) whenever the cast is not what your clients need, and for any pair the table does not list
(float ↔ bool, for instance): the check reports it as `conversion-missing`.

| Conversion | Automatic | Warning without closure |
| --- | --- | --- |
| int → float; int, float, bool → string; bool → int | yes | no |
| string → int, string → float, float → int; int, string → bool | yes | **yes** (`conversion-lossy`) |
| backed enum → string, or int when int-backed | yes (`->value`) | no |
| string or int → backed enum | yes (`::from()`) | no |
| object, list, custom type | no: give a closure, unless the custom type declares its conversions | — |

`Cast::round()`, `Cast::floor()` and `Cast::ceil()` are ready-made float → int conversions:
`->was(ApiRevision::Genesis, schema: Schema::float(), up: Cast::round())`.

A response only uses `down:`, a request only `up:`. A descriptor shared by both declares both.

## Responses

```php
final class OrderResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    public function toArray(Request $request): array
    {
        return [
            'title' => Schema::string(),
            'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
            'address' => Schema::object([
                'street' => Schema::string(),
                'zip' => Schema::string('postcode'),
            ], 'shippingAddress')->component('Address'),
            'lines' => Schema::list(Schema::object([
                'sku' => Schema::string(),
                'quantity' => Schema::int(),
            ])),
            'customer' => Schema::resource(CustomerResource::class)->whenLoaded('customer'),
        ];
    }
}
```

- Each field is read, normalized to its head type, filtered by `when()`, converted down to the client's revision, then
  serialized once. The output keeps the order of `toArray()`.
- `with()`, `additional()`, wrapping, collections and pagination work as usual.
- `OrderResource::collection($orders)` runs `toArray()` **once per render**, on a template instance whose `resource`
  is set to each order in turn, and renders every order through one projection plan. So `toArray()` must depend on
  the order only through the closures and `->when()` of its descriptors: closures reading `$this->resource` or the
  request keep working. A structure decided in plain PHP on the model (an `if` adding a key, a ternary choosing the
  descriptor, a native `$this->when($condition, Schema::string())`) would apply the first order's choice to all: such a
  resource opts out, and runs `toArray()` per item. A collection given resources built beforehand renders them through
  a fresh template: their own instance state is not read.

  ```php
  public static function revisionedPerSubject(): bool
  {
      return true;
  }
  ```

  A resource with plain values or native conditions, and a custom `ResourceCollection`, keep the per-item path.
- A consumer rendering many subjects of one declaration builds a plan itself: `$plan = $projector->plan($shape,
  $descriptors, ['title', 'total'])` keeps those head keys (a sparse fieldset), then `$plan->run($subject)` per
  subject. Build a plan per render, never keep it: it holds the closures of that declaration.
- A plain value (`'x' => $this->x`) is rendered as is in every revision, typed `any`, and reported by the check
  (`eager-value`). A native `$this->when()` or `mergeWhen()` is honoured but invisible to the schema
  (`native-condition`): prefer `->when()` / `->whenLoaded()` on the descriptor.
- **Value errors** (`'abc'` for an int, `null` for a non-nullable field, a failed conversion) follow `strict_values`:
  thrown as `ValueException` naming the class, field and revision, or cast and reported once per class and field.
  `null` (the default) follows `app.debug`.

Without Laravel, the core projects any class implementing `Revisioned`:

```php
$shapes = new Shapes();
$shape = $shapes->for(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25);
$data = (new Projector(shapes: $shapes))->project(
    $shape,
    OrderDeclaration::describeRevisioned(DeclarationKind::Response, $order),
    $order,
);
```

### A class added or retired

A resource type that exists only in some revisions implements `HasLifetime`. Its fields keep their own history inside
that lifetime.

```php
final class PodcastResource extends JsonResource implements Revisioned, HasLifetime
{
    use RevisionedResource;

    public static function revisionedLifetime(): Lifetime
    {
        return Lifetime::since(ApiRevision::Spring26); // or Lifetime::until(R), or Lifetime::since(A)->withUntil(B)
    }
}
```

- Outside its lifetime the class has no shape: rendering or validating it there throws `DefinitionException`. Answer
  404 to those clients from `ClassLifetime::exists(PodcastResource::class, ApiRevision::current())`.
- The check, `revision:cache`, `revision:export`, the lock and `assertRendersAtEveryRevision()` skip the revisions it
  does not exist in. A lock file lists the classes existing at its revision. The changelog says "The class is added." in
  its first revision and "The class is removed." in the one after its last.
- A `Schema::resource(PodcastResource::class)` field carries the same bounds (`->since(ApiRevision::Spring26)`):
  visible where the class does not exist, it is the error `resource-absent`.

## Requests

```php
final class StoreOrderRequest extends FormRequest implements Revisioned
{
    use RevisionedRequest;

    protected function body(): array
    {
        return [
            'title' => Schema::string()->required()->rules('max:255'),
            'status' => Schema::enum(OrderStatus::class)->was(ApiRevision::Winter25, name: 'state'),
            'first_name' => Schema::string()->required()->since(ApiRevision::Spring26, default: null),
            ...Schema::until(ApiRevision::Winter25, [
                'name' => Schema::string()->required(),
            ]),
        ];
    }

    protected function queryParameters(): array
    {
        return [
            'per_page' => Schema::int()->min(1)->was(ApiRevision::Genesis, name: 'limit'),
        ];
    }

    protected function upgrade(Revision $to, array $input): array
    {
        if ($to === ApiRevision::Spring26 && array_key_exists('name', $input)) {
            $input['first_name'] = strtok((string) $input['name'], ' ');
        }

        return $input;
    }
}
```

1. The rules of the client's revision are generated from the shape. Error keys use the names the client knows.
2. The input is upgraded one revision at a time. Each step renames and converts the fields (an `up:` closure receives
   typed values), calls `upgrade($to, $input)` for what a `was()` cannot express, adds the defaults of the fields added
   in `$to`, and drops the fields that no longer exist.
3. `validated()` and `safe()` return the head input, serialized back to raw values (`'paid'`, not `OrderStatus::Paid`).
   **Read the head from them, never from `input()`**, which keeps what the client sent.

- Conversions and defaults apply to present keys only, and a default never fills a key sent as `null`: a `PATCH` keeps
  "not sent" and "sent as `null`" apart.
- A `required()->since(R)` field declares what older clients get: `default: 0`, or `default: null` for an explicit
  `null`. Without it, the check fails (`required-since-without-default`), unless `missing_default` is `'null'`.
- Store and update requests share their fields through a static method returning the array; each adjusts `required()`
  and `sometimes()`.

## Resolving the revision

```php
'resolvers' => [
    HeaderResolver::class => ['name' => 'Api-Revision'],
    // PathResolver::class => ['parameter' => 'revision'],
    // QueryResolver::class => ['name' => 'revision'],
    // App\Api\AccountPinnedResolver::class,
],
'default' => 'latest', // latest | oldest | a backed value | a RevisionResolver class
```

- The `revision` middleware runs the chain. The first resolver returning a revision wins; none → `default`.
- An unknown value stops the chain and answers **400**, listing the accepted values.
- The response gets the `response_header` (`Api-Revision`), a `Vary` on the headers the chain reads, and `Deprecation`
  / `Sunset` once the revision's `#[Meta]` declares them.
- A resolver's options are named constructor arguments; the container injects the rest.

**Path mode.** With a `PathResolver` in the chain, `Route::revisioned()` prefixes its routes with the revision,
constrained to the enum values, and adds the middleware. The parameter never reaches your controllers, and `route()`
generates URLs in the request's revision.

```php
Route::revisioned(function (Router $router) {
    $router->get('/orders/{order}', [OrderController::class, 'show']);   // /winter-25/orders/1
});
```

**A custom resolver** pins the revision of an account, Stripe-style:

```php
use Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver;
use Ark4ne\ApiRevisions\Revisions\Revision;

final readonly class AccountPinnedResolver implements RevisionResolver
{
    public function resolve(Request $request): ?Revision
    {
        return $request->user()?->api_revision;   // cast to ApiRevision; null lets the chain go on
    }
}
```

## Outside HTTP

The current revision is context state: the innermost `using()`, else the revision the middleware resolved, else the
configured `default` when it is a fixed revision (the head otherwise).

```php
ApiRevision::using($account->api_revision, function () use ($account, $order) {
    Http::post($account->webhook_url, OrderResource::make($order)->resolve());
});
```

- Render inside the closure: a resource returned and serialized later renders in the outer revision.
- A queued job implementing `Laravel\Queue\CapturesRevision` stores the revision current at dispatch and runs
  (`handle()`, its middleware, `failed()`) in it. Dispatch inside a block body:
  `using($r, function () use ($order) { SendOrderWebhook::dispatch($order); })`. An arrow function would return the
  `PendingDispatch`, which pushes after `using()` has restored the outer revision.
- Only `ShouldQueue` jobs are captured: a queued notification, mailable or listener should be sent by a marked job.

## Check

```bash
php artisan revision:check                 # a table; exits 1 on any error
php artisan revision:check --format=json
php artisan revision:check --list          # the discovered classes, to spot a forgotten path
```

The check discovers the classes implementing `Revisioned` under `discovery.paths`, plus `discovery.classes`, compiles
every revision and reports a diagnostic per problem, with its class, field and revision. It never throws on your code.

| Code | Problem |
| --- | --- |
| `order-missing-case`, `order-duplicate-case` | `order()` misses a case or repeats one |
| `lifetime-inverted` | `until` before `since` |
| `since-older-than-group`, `until-newer-than-group` | a field bound outside its group's |
| `bound-repeated` | warning: `since()` or `until()` called twice |
| `history-unordered`, `history-before-since`, `history-after-until` | a `was()` repeated, on the head, or dead |
| `conversion-missing` | a type change neither automatic nor given |
| `conversion-lossy` | warning: a lossy automatic conversion without closure |
| `name-collision` | two fields under one name at a revision |
| `required-since-without-default` | a required field added later without `default:` |
| `schema-override` | warning: a rule's JSON Schema overrides the field's type |
| `native-condition`, `eager-value` | a native condition (warning) or a plain value in a resource |
| `unknown-resource` | `Schema::resource()` on a class that cannot be nested |
| `resource-absent` | a visible `Schema::resource()` field nesting a class absent at its revision ([class lifetime](#a-class-added-or-retired)) |
| `component-conflict` | one component name, two bodies |
| `definition-error` | a declaration that throws |
| `lock-mismatch`, `lock-order-changed` | a locked revision changed or moved |

In PHPUnit, `assertRevisionsAreConsistent()` (on `InteractsWithRevisions`) fails with every diagnostic.

A package (or an application) adds its own rules: a class implementing `Checking\Check`, tagged in a service
provider. The container builds it (constructor injection), and both `revision:check` and
`assertRevisionsAreConsistent()` run it after the built-in rules, once on the enum (`$context->class` is `null`) and
once per class (`kinds()` is empty on the enum, so the loop below skips it).

```php
$this->app->tag([ReservedNameCheck::class], RevisionsServiceProvider::CHECKS);
```

```php
final readonly class ReservedNameCheck implements Check
{
    public function run(CheckContext $context): void
    {
        foreach ($context->kinds() as $kind) {
            foreach ($context->order() as $revision) {
                foreach ($context->shape($kind, $revision)->fields as $field) {
                    if (in_array($field->name, ['type', 'id'], true)) {
                        $context->error('reserved-name', "'{$field->name}' is reserved.", $field->headKey, $revision);
                    }
                }
            }
        }
    }
}
```

## Lock

The real risk is a **forgotten** `since()`: a new field leaking into the revisions already published. Rather than
requiring a bound on every field, the published revisions are frozen.

```bash
php artisan revision:lock                      # locks every revision but the head that has no lock yet
php artisan revision:lock --revision=winter-25 # (re)locks one, the head included
```

- Each revision is one file, `revisions.lock/<value>.json` (`lock_path`): the structure of the JSON Schema of every
  class and kind, without documentation, and the revision before it in `order()`. Commit the directory.
- `revision:check` reports any difference (`lock-mismatch`, per field) and any locked revision that moved
  (`lock-order-changed`). Descriptions, examples and `deprecated()` never break a lock.
- The head stays free: lock a revision when you publish the next one.

## Export, components and changelog

```bash
php artisan revision:export                                  # {revision: {class: {kind: schema}}}
# a single schema when one revision, one class and one kind remain:
php artisan revision:export --revision=winter-25 --class="App\Http\Resources\OrderResource"
php artisan revision:changelog                               # Markdown, newest revision first
php artisan revision:changelog --from=genesis --to=winter-25 # --from excluded, --to included
```

- The export is a JSON Schema (draft 2020-12) per class, declaration kind and revision, read from the same shapes as
  the projection and the validation. Nested resources and components (`Schema::object([...])->component('Address')`,
  custom types) go to `$defs`. `laravel-openapi-generator` builds one OpenAPI document per revision from it.
- The changelog lists, per revision, the fields added, removed, renamed or whose type changed, each revision headed by
  its `#[Meta]`.

A generator reads the same data from PHP:

```php
use Ark4ne\ApiRevisions\Checking\Declarations;
use Ark4ne\ApiRevisions\Export\Changelog;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;

$exporter = app(JsonSchemaExporter::class);
foreach (app(Declarations::class)->at(ApiRevision::Winter25) as $class => $kinds) {
    foreach ($kinds as $kind) {
        $schema = JsonSchemaExporter::objects($exporter->export($class, $kind, ApiRevision::Winter25));
    }
}
$markdown = app(Changelog::class)->markdown(array_keys(app(Declarations::class)->declarations()), ApiRevision::Genesis, ApiRevision::Winter25);
```

- `Declarations::declarations()` lists the discovered classes with their declaration kinds; `at($revision)` keeps the
  classes existing at that revision.
- These classes, the structure printed by `revision:export` and `Changelog::changes()` are public contract: from 1.0,
  they change only in a major version.

## Retiring a revision

When the oldest revision `P` has no client left:

1. `php artisan revision:prune` lists what retiring it makes dead, with file and line: each `until(P)` (delete the
   field), `was(P)` (delete that `was()`) and `since(P)` (delete the bound, already a no-op). It is informative and
   always succeeds. It does not read class lifetimes: delete a class whose `revisionedLifetime()` ends at `P`, and
   the `since(P)` bound of one starting there.
2. Delete the case from the enum and from `order()`. PHPStan reports any reference left.
3. Delete `revisions.lock/<P>.json`, then re-lock the new oldest revision, whose recorded predecessor is gone:
   `php artisan revision:lock --revision=<new oldest>`.
4. `php artisan revision:check`.

A queued job captured in `P` and run after the retirement fails with `UnknownRevisionException`.

## Testing

```php
abstract class TestCase extends BaseTestCase
{
    use InteractsWithRevisions;
}
```

- `withRevision(ApiRevision::Winter25)` sends the revision as the first resolver of the chain reads it: a header, the
  path parameter (through `URL::defaults()`, for `route()`), or the query.
- `->assertMatchesRevisionSchema(OrderResource::class)` on a `TestResponse` validates the body against the schema of
  the revision of its `Api-Revision` header (or the revision given), objects closed: an undeclared field fails. The
  `data` of a wrapped body is validated, a list item by item.
- `assertRendersAtEveryRevision(OrderResource::class, $order)` renders a head fixture at every revision (or those
  given) and validates each rendering: a contract test.
- `assertRevisionsAreConsistent()` runs the check.

The schema assertions need `composer require --dev opis/json-schema`.

## Custom types

A custom type is a `Schema\Descriptor` subclass: it normalizes, serializes and describes its value. Register it at boot
under a name, then use it on `Schema`:

```php
// AppServiceProvider::boot(), with use Ark4ne\ApiRevisions\Schema\TypeRegistry;
TypeRegistry::register('money', MoneySchema::class);

'total' => Schema::money('total_cents'),
```

```php
use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Definition\TypeDefinition;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\InvalidValue;

/** @extends Descriptor<Money> */
final class MoneySchema extends Descriptor
{
    public function normalize(mixed $value): mixed
    {
        return $value instanceof Money ? $value : new InvalidValue($value, Kind::Custom, null);
    }

    public function serialize(mixed $value): mixed
    {
        return $value instanceof Money ? ['amount' => $value->amount, 'currency' => $value->currency] : $value;
    }

    protected function type(): TypeDefinition
    {
        return TypeDefinition::custom('money', ['type' => 'object', 'required' => ['amount', 'currency']]);
    }
}
```

A type implementing `Schema\DeclaresConversions` registers its own conversions (to and from a float, for instance).
It is exported once as a component.

A type whose fragment varies by instance while its values stay the same (a relationship naming its related type, for
instance) declares it with `$converters->identity('relation')` in `conversions()`. A `was(schema:)` that only changes
its fragment then needs no conversion (no `conversion-missing`), and the change still shows in the export, the lock
and the changelog. Such a type is exported in place, not as a component, so two instances in one revision never
conflict.

PHPStan does not know `Schema::money()` by itself. The package's `extension.neon` (included by
`phpstan/extension-installer`, or by hand with `includes: [vendor/ark4ne/laravel-api-revisions/extension.neon]`) types
it once each registered name is mapped to its class:

```neon
parameters:
    apiRevisions:
        types:
            money: App\Schema\MoneySchema
```

`Schema::money(...)` then takes the parameters of `MoneySchema`'s constructor and returns a `MoneySchema`.

## Performance and Octane

- A shape is compiled once per class and revision. `revision:cache` writes every shape to
  `bootstrap/cache/revisions.php`; `php artisan optimize` runs it and `optimize:clear` removes it (or `revision:clear`), like the route cache.
  Run it again after a deploy that changes declarations. Closures are never cached: they come from `toArray()` /
  `body()` at run time.
- Per item, only the sources of the visible fields run, then the compiled conversions.
- **Octane**: the current revision is reset on every request. Add the configuration and the compiled shapes to
  `octane.warm`, so they are built once per worker rather than once per request:

```php
// config/octane.php
'warm' => [
    ...Octane::defaultServicesToWarm(),
    \Ark4ne\ApiRevisions\Laravel\RevisionsConfig::class,
    \Ark4ne\ApiRevisions\Compilation\Shapes::class,
],
```

## Configuration

`config/revisions.php` (`php artisan vendor:publish --tag=revisions-config`):

| Key | Default | Role |
| --- | --- | --- |
| `enum` | `null` | your revision enum; left `null`, the library works on one implicit revision (`Revisions\Unrevisioned`, `head`): no history, no revision header, nothing locked |
| `resolvers` | header `Api-Revision` | the chain resolving the revision of a request |
| `default` | `'latest'` | the revision when no resolver answers: `latest`, `oldest`, a backed value, a resolver class |
| `response_header` | `'Api-Revision'` | the header naming the revision of a response |
| `missing_default` | `'error'` | a `required()->since()` field without default: `'error'` or `'null'` |
| `strict_values` | `null` | value errors throw (`true`) or are cast and reported (`false`); `null` follows `app.debug` |
| `date_format`, `date_time_format` | `'Y-m-d'`, `'Y-m-d\TH:i:sP'` | formats of `Schema::date()` / `dateTime()` without `format()` |
| `discovery.paths`, `discovery.classes` | `[app_path()]`, `[]` | where the commands find the `Revisioned` classes |
| `cache_path` | `bootstrap/cache/revisions.php` | the compiled shapes of `revision:cache` |
| `lock_path` | `revisions.lock` | the directory of the lock files |
