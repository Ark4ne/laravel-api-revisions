# Decided model — `ark4ne/laravel-api-revisions` v1

> The reference every user story implements and tests. Source: the design study
> ["Révisions d'API — étude de conception"](https://claude.ai/artifact/XjJeW7sm8YpJppNZhTpKZK) (2026-11-10), amended by
> the decisions of §14. Where this document and the study disagree, **this document wins**. A change to this model is
> recorded in §14 before it is coded.

## 1. Scope

A PHP library that describes **typed schemas** and their **evolution through ordered revisions**, Stripe-style. The
code always declares the latest shape (the **head**); older shapes are declared on the fields that changed. From that
single declaration the library derives:

- the **response** of every revision (projection, "down");
- the **validation and upgrade** of every request to the head (upgrade, "up");
- the **validation rules** of every revision;
- a **neutral export** (JSON Schema) and a **changelog**;
- a **check** of the declarations and a **lock** of the published revisions.

It knows neither JSON:API nor OpenAPI: `ark4ne/laravel-json-api` and `ark4ne/laravel-openapi-generator` build on it.

**Non-goals (v1)**

- No dependency on `laravel-json-api` or `laravel-openapi-generator`.
- No filters, sorts or pagination in response schemas: they belong to requests (`query()`).
- No global "change" classes: the history lives on the fields, plus one `upgrade()` hook for multi-field changes.
- No revision per resource: **one global timeline** for the whole API.

## 2. Revisions

### 2.1 A revision is an enum case

```php
use Ark4ne\ApiRevisions\Revisions\IsRevision;
use Ark4ne\ApiRevisions\Revisions\Meta;
use Ark4ne\ApiRevisions\Revisions\Revision;

enum ApiRevision: string implements Revision
{
    use IsRevision;

    #[Meta('First published revision', publishedAt: '2025-06-01')]
    case Genesis = 'genesis';

    #[Meta('Totals become decimal strings', publishedAt: '2025-12-01')]
    case Winter25 = 'winter-25';

    #[Meta('Totals in cents, `state` renamed `status`', publishedAt: '2026-04-01', deprecatedAt: '2026-11-01')]
    case Spring26 = 'spring-26';

    #[Meta('Tags and notes; `name` replaced by `first_name` / `last_name`')]
    case Atlas = 'atlas';

    public static function order(): array
    {
        return [self::Genesis, self::Winter25, self::Spring26, self::Atlas];
    }
}
```

- Any case name; the **backed value** is what clients send and receive (header, path segment, query).
- `order()` is **explicit**, oldest first, every new revision appended. A fixer sorting the cases must never be able to
  reorder history. The check (§9) verifies that `order()` holds every case exactly once.
- The **head** is the last case of `order()`. There is no `null` revision: an unresolved request gets the configured
  default (§8).
- Comparing cases of two different enums is a programming error (`DefinitionException`).

### 2.2 `IsRevision`

| Method | Role |
| --- | --- |
| `isBefore(R)` / `isAfter(R)` / `isAtLeast(R)` / `isAtMost(R)` | comparison by index in `order()` |
| `previous()` / `next()` | walk the timeline (`null` past the ends) |
| `static latest()` / `static oldest()` | bounds; `latest()` is the head |
| `static current()` | the revision of the current context (§10) |
| `static using(R, Closure)` | runs a closure in revision `R`, restores the previous one (even on exception) |
| `meta()` | the `Meta` of the case (`description`, `publishedAt`, `deprecatedAt`, `sunsetAt`: days, reached from the day included, D30), empty when absent |

`meta()` feeds the export, the changelog and the `Deprecation` / `Sunset` response headers.

## 3. Schema vocabulary

Every helper is a static method of `Schema` returning a **typed, immutable, lazy descriptor**, usable anywhere:
resources, form requests, ad hoc responses.

| Helper | Descriptor | Specific modifiers |
| --- | --- | --- |
| `Schema::string()` | `StringSchema` | `min()`, `max()`, `format()`, `pattern()` |
| `Schema::int()` / `Schema::float()` | `IntSchema` / `FloatSchema` | `min()`, `max()` |
| `Schema::bool()` | `BoolSchema` | — |
| `Schema::date()` / `Schema::dateTime()` | `DateSchema` / `DateTimeSchema` | `format()` (default from configuration) |
| `Schema::enum(OrderStatus::class)` | `EnumSchema` | values exported from the backed enum |
| `Schema::list(Schema::string())` | `ListSchema` | `min()`, `max()` on the size |
| `Schema::object([...])` | `ObjectSchema` | free nesting |
| `Schema::money()` | a custom type (`MoneySchema extends Descriptor`) | registered in the type registry, exposed by macro |
| `Schema::rule(['array', new MetaRule])` | `RuleSchema` | escape hatch; `any` in the export unless `describe()`; a rule implementing `DescribesSchema` (here or in `rules()` of any descriptor, D36) adds its JSON Schema keywords |
| `Schema::resource(CustomerResource::class)` | `ResourceSchema` | another `Revisioned` class, projected at the same revision with its own shape (§6.1) |

Common to all: `nullable()`, `required()`, `sometimes()`, `describe()`, `example()`, `deprecated()`,
`rules(...)` (strings, rule objects, closures; opaque to the core), `when(Closure)`, `whenHas()`.

**One closure signature everywhere.** Every closure the library calls — source, `when()`, `down:`, `up:` — receives
`(mixed $value, mixed $subject)`: the value of the field **at that step** and the subject being read (§6.1; for a
request, the input). A closure may declare fewer parameters (`fn () => …`, `fn (int $cents) => …`): PHP ignores the
extra arguments.

- **Lazy source.** The source is the **last** argument of a helper (the only one for scalars): a key
  (`Schema::string('title')`, a dotted path `'customer.name'`) or a closure
  (`Schema::string(fn ($value, Order $order) => $order->fullName())`). Without a source, the field reads the key it is declared
  under. **A source is never an evaluated value.** This is what lets the library compile a schema without a real
  subject ("describe mode"): no closure runs during compilation. How values are read, normalized and serialized: §6.
- **Conditions.** `->when(fn () => …)` keeps the field in the schema, exported as optional. A native Laravel
  `$this->when()` returns a `MissingValue` and is invisible to the schema: it gets a diagnostic.
- **Typing.** Each helper returns its concrete class: autocompletion, and PHPStan rejects `->min()` on a `BoolSchema`.
- **Custom rules in the export.** A rule implementing `Schema\DescribesSchema::toSchema(): array` enriches the export;
  otherwise the base type and `describe()` are exported.
- **Immutability.** Every modifier returns a copy.

## 4. History: `since`, `until`, `was`

### 4.1 Inclusive bounds

Every revision named in a declaration is **inclusive** (decision D1, the same as `laravel-json-api` 2.1):

| Declaration | Meaning | Applies to |
| --- | --- | --- |
| `->since(R)` | the field exists **from `R` included** | field or group |
| `->until(R)` | the field exists **up to `R` included** (removed in `R->next()`) | field or group |
| `->was(R, name:, schema:, down:, up:)` | **up to `R` included**, the field had another name, another type, or both | field only |

A field without annotation exists in every revision.

**Writing rule:** when a field changes in a new revision `N`, write `was(<the revision that was the head until now>)`,
i.e. `was(N->previous())`. When a field is removed in `N`, write `until(N->previous())`. When a field is added in `N`,
write `since(N)`.

**Why inclusive:** retiring the oldest revision `P` is deleting the case `P`. PHPStan then reports exactly the
declarations that served only the clients of `P` (`was(P)`, `until(P)`), and nothing else is dead.

### 4.2 `was()`: chained deltas

```php
'status' => Schema::enum(OrderStatus::class)
    ->was(ApiRevision::Winter25, name: 'state'),

'total' => Schema::int()
    ->was(ApiRevision::Winter25, schema: Schema::string(),
        down: fn (int $cents): string => number_format($cents / 100, 2, '.', ''),
        up: fn (string $amount): int => (int) round(((float) $amount) * 100))
    ->was(ApiRevision::Genesis, schema: Schema::float()),   // string ↔ float: automatic
```

- A `was()` is a **delta from the next newer shape** (the next newer `was()`, or the head): an argument left out keeps
  the value of that newer shape. `was(Genesis, schema: float())` above keeps the name `total`; a `was(Genesis, …)` on
  `status` would keep `state`.
- The `was()` of a field are ordered by revision, never by call order. Their revisions must be strictly decreasing
  when sorted, all distinct, and not older than the field's `since` (§4.4).
- Conversions between two consecutive shapes are named by direction: **`down:`** (response, newer → older) and
  **`up:`** (request, older → newer). Each is optional when the pair of types is automatic (§5).
- `name:` also covers renames: no fourth word.

**Reference shapes** (the example above plus §4.3):

| Client | Key of `status` | `total` | `tags`, `notes` | `name` |
| --- | --- | --- | --- | --- |
| `Atlas` (head) | `status` | `1250` | present | absent |
| `Spring26` | `status` | `1250` | absent | present |
| `Winter25` | `state` | `"12.50"` | absent | present |
| `Genesis` | `state` | `12.5` | absent | present |

### 4.3 Groups

A group applies `since` or `until` to every descriptor it holds; it returns a plain array, spread with string keys
(PHP 8.1+): nothing is evaluated at the spread.

```php
return [
    'title' => Schema::string(),

    ...Schema::since(ApiRevision::Atlas, [
        'tags' => Schema::list(Schema::string()),
        'notes' => Schema::string()->nullable(),
    ]),

    ...Schema::until(ApiRevision::Spring26, [
        'name' => Schema::string(fn () => $this->fullName()),
    ]),
];
```

`Schema::since(R, [...], default: …)` also sets the default of every member (§7.3); a member may override it. A
member without its own default takes the group's even when it keeps a newer own `since`: the clients older than its
`since` do not send it either.

### 4.4 Consistency rules

Checked when declared (programming error → `DefinitionException`) or by the check (§9, diagnostic):

1. A field's `since` older than its group's: **error**. Newer: the newer one wins. Mirror for `until` (D24): a
   field's `until` newer than its group's is an **error**, an earlier one wins. A group never counts as a second call
   (rule 5).
2. `until` must be at or after the `since` of the same field or of its group.
3. The revisions of the `was()` of a field are distinct; every one is older than the head and at or after the `since`.
4. A rename cannot target a name already present in the same revision (checked revision by revision).
5. Calling `until()` (or `since()`) twice on the same descriptor keeps the last call and reports a diagnostic
   (`bound-repeated`). The default belongs to the `since()` call it is given with: a later `since()` without default
   leaves none; `until()` keeps it.

### 4.5 Central invariant: one selection function

One **pure** function gives, for a list of head descriptors and a revision `R`, the shape of `R`:

- the visible fields (lifetime covers `R`);
- for each one, its **name**, **descriptor** and **conversion chain** at `R` — the `was()` with revisions `≥ R`, from
  the newest to the oldest;
- for requests, the defaults of the fields added after `R`.

The compiler applies it once per `(class, revision)`; the projector, the upgrader, the rule generator, the check and
the export all read its result. **The response, the validation, the export and the lock agree by construction.**

## 5. Conversions

A type change between **scalars** (string, int, float, bool) needs no closure: the library converts automatically, in
both directions, with PHP cast semantics. The author gives `down:` / `up:` whenever the cast is not what the clients
need: **it is the author's call** (D25). Pairs that may lose information without a closure get a non-blocking
`conversion-lossy` **warning** from the check, so the risk is visible.

| Conversion | Automatic | Lossy-capable (warning without closure) |
| --- | --- | --- |
| int → float, int / float / bool → string, bool → int | yes | no |
| string → int, string → float, float → int, int / string → bool | yes (PHP cast) | **yes** |
| backed enum → string / int | yes, via `->value` | no |
| string / int → backed enum | yes, `::from()`; a failure is a validation error (up) or a value error (down) | no |
| nullable → non-nullable (up) | no | `default:` required |
| object, list, custom type | no: explicit closure, unless the custom type declares its conversions | — |

- On the request side, the rules of the older shape get what its type implies (`integer`, `numeric`, the enum
  values), so an automatic up conversion only sees valid input.
- Explicit casts remain available: `Cast::round()`, `Cast::floor()`, `Cast::ceil()`.
- **One direction per declaration kind** (D27): a response is only ever projected down, a request only ever upgraded
  up. The shape of a response plans and checks the `down` conversions only, the shape of a request the `up` ones only.
  A descriptor shared by both declares `down:` and `up:`; each side uses its own.
- Conversions are **registered** by `(source type, target type, direction)`; a custom type registers its own.

## 6. Response side (projection, "down")

The resource always declares the head; the library derives every older shape at rendering time. Every field goes
through the same pipeline: **read** its source on the subject → **normalize** to its head type → **convert** down to
the shape of `R` → **serialize** for JSON.

```php
final class OrderResource extends JsonResource implements Revisioned
{
    use RevisionedResource;

    public function toArray(Request $request): array
    {
        return [
            'title' => Schema::string(),                                       // reads $order->title
            'reference' => Schema::string('ref'),                              // reads $order->ref
            'customer_name' => Schema::string('customer.name'),                // reads $order->customer->name
            'status' => Schema::enum(OrderStatus::class)                       // reads $order->status (an OrderStatus)
                ->was(ApiRevision::Winter25, name: 'state'),                   // still reads `status`, renders `state`
            'total' => Schema::int(fn ($value, Order $order) => $order->total_cents) // (value, subject)
                ->was(ApiRevision::Winter25, schema: Schema::string(),
                    down: fn (int $cents): string => number_format($cents / 100, 2, '.', ''))
                ->was(ApiRevision::Genesis, schema: Schema::float()),
            'address' => Schema::object([                                      // its source is the subject of its children
                'street' => Schema::string(),
                'zip' => Schema::string('postcode'),
            ], 'shippingAddress'),
            'lines' => Schema::list(Schema::object([                           // each item is the subject of the object
                'sku' => Schema::string(),
                'quantity' => Schema::int(),
            ]), 'lines'),
            'customer' => Schema::resource(CustomerResource::class),           // another Revisioned class, same revision

            ...Schema::since(ApiRevision::Atlas, [
                'tags' => Schema::list(Schema::string(), fn ($value, Order $order) => $order->tags->pluck('name')),
                'notes' => Schema::string()->nullable(),
            ]),

            ...Schema::until(ApiRevision::Spring26, [
                'name' => Schema::string(fn () => $this->fullName()),           // closure bound to the resource
            ]),
        ];
    }
}
```

### 6.1 The subject

The **subject** is what sources read. It is given to the projector by the consumer:

| Context | Subject |
| --- | --- |
| `RevisionedResource` (Laravel `JsonResource`) | `$this->resource` (the wrapped model, array or object) |
| `Projector::project($shape, $descriptors, $subject)` | whatever the consumer passes (`laravel-json-api`: its model) |
| child of `Schema::object([...], $source)` with a source | the value of that source |
| child of `Schema::object([...])` without source | the parent's subject (the object only groups fields) |
| item of `Schema::list($items, $source)` | each element of the iterable |
| `Schema::resource(Other::class, $source)` | the value of the source, wrapped and projected by `Other` |
| compilation ("describe mode") | the `Probe`: no source, closure or condition ever runs |

### 6.2 Sources

| Declaration | Reads |
| --- | --- |
| `Schema::string()` under the key `'title'` | `title` on the subject: the **head key**, whatever the revision |
| `Schema::string('ref')` | `ref` on the subject |
| `Schema::string('customer.name')` | a dotted path, segment by segment; `null` as soon as a segment is `null` |
| `Schema::string(fn ($value, Order $order) => …)` | the closure, called with `($value, $subject)` (below) |
| `Schema::string(fn () => $this->fullName())` | the same call; `$this` is the class where it is written (the resource) |

For a source closure, `$value` is the value of the **declared key** when the subject already holds it **without side
effect**, otherwise `null`: an array offset that exists, an initialized property; for an Eloquent model (Laravel
layer) an attribute present in `getAttributes()` (cast as usual) or a loaded relation. Reading `$value` never
triggers a lazy load, an accessor-only computation or a strict-mode error.

Reading a key (`SourceReader`, pure PHP): arrays and `ArrayAccess` by offset; objects by public property or `__get()`
(so Eloquent attributes, casts and accessors apply as usual). A key the subject does not have reads `null`; add
`->whenHas()` to omit the field instead. Errors of the subject itself (Eloquent strict mode on a missing attribute,
lazy loading prevented) are not caught: they reveal a bug of the declaration.

`Schema::string(fn () => $this->resource->title)` works, but `Schema::string()` says the same thing and keeps the
field readable by the tools (a key source is known to the export; a closure is opaque).

### 6.3 Normalize, convert, serialize

After reading, the value is **normalized** to the head type: conversions always work on these PHP values, never on
raw subject data or on JSON.

| Kind | Accepted from the source | Normalized value | Serialized |
| --- | --- | --- | --- |
| string | `string`, `int`, `float`, `Stringable` | `string` | string |
| int | `int`, integral `float`, integral numeric string (D32) | `int` | number |
| float | `int`, `float`, numeric string without blanks, finite (D32) | `float` | number |
| bool | `bool`, `0` / `1`, `'0'` / `'1'` | `bool` | boolean |
| enum | the enum case, or its backed value (`::from()`; an int-backed enum reads it as `int` does, D33) | the `BackedEnum` case | `->value` |
| date / dateTime | `DateTimeInterface`, a string parsed without warning nor surrounding blanks, an int timestamp (UTC) (D33) | `DateTimeImmutable` | `format()` of the type (D29) |
| list | any `iterable` (arrays, generators, Laravel collections) | `list` of projected items (D34) | array |
| object | an array or object, the subject of its children (§6.1) | `array<string, mixed>` of projected children (D34) | object (`{}` when empty) |
| custom type | the type's `normalize()` | the type's | the type's `serialize()` |
| any (`Schema::rule()`, eager value) | anything | as is | `JsonSerializable`, scalars and arrays as is |

- **`null`** skips normalization and conversions: it is rendered `null` in every older shape. A non-nullable field
  resolving `null` is a **value error**.
- A value the table does not accept (`'abc'` for an int, `12.5` for an int, an unknown enum value) is a **value
  error**.
- Value errors depend on `strict_values` (configuration, default `true` when `app.debug`, `false` otherwise): strict →
  `ValueException` naming the class, the field and the revision; lenient → PHP cast (`(int)`, `(string)`…, an unknown
  enum value renders its raw value) and the error goes to the configured reporter (Laravel: `report()`, once per class
  and field).
- The **down conversions** (§5) receive normalized values: an `enum → string` converter gets the case, a `down:`
  closure gets `($value, $subject)` — the value of the next newer shape and the subject:
  `down: fn (int $cents, Order $order): string => …`.
- **Serialization** happens last, once, on the value of the shape of `R`.

### 6.4 History and sources

- **The subject has one version: the current one.** A `was()` never has its own source: an older value is always
  derived from the head value through the down chain. A `schema:` given with a source throws
  `DefinitionException::sourceInHistory()`.
- A renamed field reads its **head** key (`status` is read for clients receiving `state`).
- An older value that needs more than the head value: use the subject passed to `down:`, or declare the old field
  `until()` with its own source next to the new one.
- **Presence conditions** (`->whenHas()`, `->whenLoaded()`) run **before** the source: they protect it (no lazy load,
  no missing attribute).
- **`->when(fn ($value, Order $order) => …)`** runs **after** the source and the normalization: its `$value` is the
  field's head value. A field whose condition fails is omitted (never `null`). An expensive source is guarded by a
  presence condition, not by `when()`.

### 6.5 Projection at revision `R`

1. Take the compiled shape of `(class, R)` (§4.5).
2. For each visible field, in the head order: evaluate its presence conditions, then its source, on the subject.
3. Normalize the value to the head type (§6.3), then evaluate its `when()` conditions.
4. Apply its down chain, newest shape to oldest, up to the shape of `R`.
5. Serialize it and write it under its name at `R`.
6. Return `array<string, mixed>` (key → value). The consumer (Laravel `JsonResource`, `laravel-json-api`, anything)
   places it in its document.

A field removed by `until()` stays computable while clients use it: its source is usually a closure rebuilding it from
the new fields. Behaviour changes without schema impact stay in business code:
`if (ApiRevision::current()->isAtMost(ApiRevision::Spring26)) { … }`.

### 6.6 In a Laravel `JsonResource`

- `RevisionedResource` overrides `resolve()`: it calls `toArray()`, projects the descriptors on `$this->resource` at
  `ApiRevision::current()`, and keeps the rest of `JsonResource` (`with()`, `additional()`, wrapping, collections,
  pagination) unchanged.
- Closures written in `toArray()` are bound to the resource: `$this->resource->title`, `$this->title` (delegated to
  the model) and the subject argument all reach the same model.
- A plain value (`'x' => $this->x`) is rendered as is in every revision, typed `any` in the export, and reported by
  the check (`eager-value`). A native `$this->when()`, `whenLoaded()` or `mergeWhen()` is honoured at run time but
  invisible to the schema (`native-condition`): use `->when()` / `->whenLoaded()` on the descriptor.
- The Laravel layer adds to the core: the `Arrayable` normalization of `any` values, `->whenLoaded('relation')`
  (a condition on the subject), and the reporter of lenient value errors.

## 7. Request side (validation and upgrade, "up")

The request declares the head input with the same helpers. The library validates in the client's revision, then
upgrades the input to the head: **the controller only ever sees the head**.

```php
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
            'address' => Schema::object([
                'street' => Schema::string()->required(),
                'zip' => Schema::string()->rules(new FrenchZip()),
            ]),
            'price' => Schema::money()->required(),
            'meta' => Schema::rule(['array', new MetaRule()]),

            ...Schema::since(ApiRevision::Atlas, [
                'first_name' => Schema::string()->required(),
                'last_name' => Schema::string()->required(),
                'priority' => Schema::int()->required()->since(ApiRevision::Atlas, default: 0), // overrides the group default
            ], default: null),

            ...Schema::until(ApiRevision::Spring26, [
                'name' => Schema::string()->required(),
            ]),
        ];
    }

    protected function upgrade(Revision $to, array $input): array
    {
        if ($to === ApiRevision::Atlas && array_key_exists('name', $input)) {
            [$input['first_name'], $input['last_name']] = explode(' ', (string) $input['name'], 2) + [1 => ''];
            unset($input['name']);
        }

        return $input;
    }
}
```

- The method is `body()`, not `attributes()` (already used by `FormRequest` for labels). Query parameters (filters,
  sorts, include, pagination) are declared the same way in `query()`.

### 7.1 Flow

1. The middleware resolved the client's revision `R` (§8).
2. The rules of the shape of `R` are generated and the input is validated against them: **error keys use the names
   the client knows** (`state`, not `status`).
3. The input is upgraded **step by step** from `R` to the head. For each step `X → X->next()`:
   1. the `was(X)` of every field, all read from a **snapshot** of the input at the start of the step (so rename
      chains `x → y`, `y → z` lose nothing): rename to the newer name, then `up` conversion. The value is
      **normalized** to the type of the shape of `X` first, so an `up:` closure receives typed values whatever the
      wire format (`3`, never `"3"`): it receives `($value, $snapshot)`, the snapshot in the shape of `X`;
   2. the hook `upgrade(X->next(), $input)`;
   3. the defaults of the fields `since(X->next())`, written in the shape of `X->next()` (D23);
   4. the fields `until(X)` still present are dropped: they do not exist in `X->next()`.
4. `validated()` returns the input in the head shape, **serialized back to raw values** (`'paid'`, not
   `OrderStatus::Paid`): exactly what Laravel returns today for a head client (D26).

### 7.2 Absent or null

For a `PATCH`, "not sent" and "sent as `null`" differ. Conversions and defaults only apply to **present** keys, and a
default **never** fills a key explicitly sent as `null`.

### 7.3 Required fields added later

A `required()->since(R)` field is never sent by an older client: it must declare what the controller receives.

```php
Schema::int()->required()->since(ApiRevision::Atlas, default: 0);        // a value
Schema::string()->required()->since(ApiRevision::Atlas, default: null);  // an explicit null
Schema::string()->required()->since(ApiRevision::Atlas);                 // ✗ the check fails
```

`default:` uses a sentinel (`Support\Undefined`) to tell "no default" from "default null". The configuration option
`missing_default: 'null'` turns the missing default into `null` for whoever accepts it; the default remains `'error'`.

### 7.4 Store and Update

Same fields, different presence rules. The fields go in a reusable **fragment** (a static method or an invokable class
returning the array); each request adjusts `required()` / `sometimes()`. No dedicated API in v1 (recipe only).

## 8. Revision resolution (Laravel)

```php
// config/revisions.php
return [
    'enum' => App\Api\ApiRevision::class,
    'resolvers' => [
        HeaderResolver::class => ['name' => 'Api-Revision'],
        // PathResolver::class  => ['parameter' => 'revision'],
        // QueryResolver::class => ['name' => 'revision'],
        // App\Api\AccountPinnedResolver::class,
    ],
    'default' => 'latest',            // latest | oldest | a backed value | a RevisionResolver class
    'response_header' => 'Api-Revision',
    'missing_default' => 'error',     // error | null (§7.3)
    'strict_values' => env('APP_DEBUG', false), // value errors throw (true) or are cast and reported (false) (§6.3)
    'date_format' => 'Y-m-d',         // Schema::date() without format() (D29)
    'date_time_format' => 'Y-m-d\TH:i:sP', // Schema::dateTime() without format(), RFC 3339 (D29)
    'discovery' => [
        'paths' => [app_path()],      // scanned for classes implementing Revisioned
        'classes' => [],              // extra classes outside the paths (packages, modules)
    ],
    'cache_path' => base_path('bootstrap/cache/revisions.php'), // revision:cache, run by `artisan optimize`
    'lock_path' => base_path('revisions.lock'),
];
```

- A **chain of resolvers**: the first one returning a revision wins. A custom resolver implements
  `RevisionResolver::resolve(Request $request): ?Revision` (account or token pinning, Stripe-style).
- Middleware `ResolveRevision`: runs the chain, then `default`; an unknown value → **400**; stores the revision in the
  `scoped` context binding; adds the response header, `Vary` on the header in header/query mode, and `Deprecation` /
  `Sunset` from `meta()`.
- **Path mode**: macro `Route::revisioned(fn () => …)` adds a `{revision}` prefix constrained to the enum values; the
  middleware removes the parameter (`forgetParameter`) and sets `URL::defaults(['revision' => …])`. Path and header
  modes may coexist in the chain.

## 9. Check and lock

The consistency is not enforced by syntax but **checked**: command `revision:check` (CI) and a PHPUnit assertion.

The check discovers the declaring classes — the classes implementing `Revisioned` found under `discovery.paths`,
plus `discovery.classes` — compiles every revision, and reports diagnostics. `revision:check --list` prints the
discovered classes, so a forgotten path is visible.

| Code | Rule | Target |
| --- | --- | --- |
| `order-missing-case` / `order-duplicate-case` | a case absent from `order()`, or present twice | enum |
| `lifetime-inverted` | `until` before `since` | all |
| `since-older-than-group` | a field `since` older than its group's | all |
| `until-newer-than-group` | a field `until` newer than its group's (D24) | all |
| `bound-repeated` | `since()` or `until()` called twice on one descriptor, naming the replaced revision (§4.4 rule 5; severity: Q16) | all |
| `history-unordered` | two `was()` on the same revision, or a `was()` not older than the head | all |
| `history-before-since` | a `was()` older than the field's `since` (dead declaration) | all |
| `conversion-missing` | a type pair neither automatic nor provided, in the direction of the declaration kind | all |
| `conversion-lossy` | **warning**: a lossy-capable automatic conversion without closure (§5) | all |
| `name-collision` | two fields under the same name in one revision | all |
| `required-since-without-default` | `required()->since(R)` without `default:` | requests |
| `schema-override` | **warning**: a `DescribesSchema` keyword overrides a keyword of the field's type (`type`, `format`, `minimum`, `enum`…) with another value (D36) | all |
| `native-condition` | a Laravel `MissingValue` / `MergeValue` in a declaration | all |
| `eager-value` | a plain value instead of a descriptor (typed `any`, the same in every revision) | responses |
| `unknown-resource` | `Schema::resource()` on a class that is not `Revisioned` | all |
| `lock-mismatch` | the shape of a locked revision differs from its lock | all |
| `lock-order-changed` | a locked revision moved in `order()` | enum |

Every diagnostic names the class, the field and the revision. The check never throws on application code: it reports.

**Lock.** The real risk is not a missing `since`, it is a **forgotten** one: a new field leaks into the old revisions.
Rather than forcing `since` everywhere, the published revisions are frozen, `composer.lock`-style:

- `revision:lock` writes a snapshot (normalized JSON Schema, one file per revision) of every class at every revision
  except the head, versioned in the repository; `--revision=` locks one explicitly. `#[Meta]` plays no part (D21).
- In CI, any difference between a computed shape and the lock of a frozen revision fails the build.
- The head stays free. The lock also records `order()`, so a published revision never moves.

## 10. Context, Octane, performance

- The current revision is **context state, never a static variable**. `Revision::current()` reads, in order: the
  innermost `using()`, the `scoped` binding set by the middleware, the configured default (outside a request: the
  configured `default` when it is a fixed revision, the head otherwise, D31).
- **Outside HTTP** (webhooks, jobs, broadcasting, notifications) the code targets a revision explicitly:
  `ApiRevision::using($account->apiRevision, fn () => $webhook->send(OrderResource::make($order)))`. A queued job
  captures the revision **when dispatched**, not when run. The revision does not outlive the closure of `using()`:
  render inside it (a resource returned and serialized later renders in the outer revision).
- **Octane:** the `scoped` binding is reset on every request; no state survives a request.
- **Compilation:** the shape is computed once per `(class, revision)` and memoized. `revision:cache` (like
  `route:cache`) writes the compiled shapes to `bootstrap/cache/revisions.php`; value closures are never serialized:
  they come from `toArray()` / `body()` at run time. `revision:cache` / `revision:clear` are registered with
  `ServiceProvider::optimizes()`: **`php artisan optimize` builds it and `optimize:clear` removes it**, like the
  route, config and event caches. The lock (§9) is unrelated: a committed snapshot, never written by `optimize`.
- **Per item:** only the sources of the visible fields are evaluated, then the precompiled conversions run.
- **Measure:** a benchmark renders a reference collection at the head and at the oldest revision; a regression beyond
  the tolerance fails CI.

## 11. Exports and tooling

| Tool | What it does | Layer |
| --- | --- | --- |
| JSON Schema export | one schema per class and per revision; revision descriptions and deprecations included | core |
| `$ref` fragments | a reused object (address, money) becomes a component; its history applies everywhere | core |
| Changelog | diff of two consecutive revisions (additions, removals, renames, type changes), in Markdown | core |
| HTTP tests | `->withRevision(R)`, `assertMatchesRevisionSchema(R)` | Laravel / Testing |
| Contract tests | a head fixture rendered at `R`, validated against the schema of `R` | Laravel / Testing |
| Prune | `revision:prune` lists the `since` / `was` / `until` made dead by a retired revision | Laravel |

Later (not v1): `oneOf` polymorphism, TypeScript types per revision, a PHPStan extension checking that a source closure
returns the declared type.

## 12. Integrations and migration

- **`laravel-json-api` 3.0** replaces its describer with `Schema` descriptors and keeps everything JSON:API-specific:
  relationships with history (`include`, `included`), type renames, attribute ↔ relationship changes, `fields` /
  `include` / `sort` / `filter` upgraded per revision, `Fields` / `Includes` rules computed from the shape of the
  client's revision. Its 2.1 declarations map 1:1 (same inclusive bounds).
- **`laravel-openapi-generator`** reads the JSON Schema export of each revision and writes one document per revision
  (`--revision=spring-26` or all). It never knows `since` or `was`.
- **Migration path:** publish the library with an adapter accepting the old describer → migrate resources one by one
  (no annotation = every revision) → `revision:lock` the first revision → remove the old describer in a major.

## 13. Differences with `laravel-json-api` 2.1

| Topic | json-api 2.1 | api-revisions v1 | Why |
| --- | --- | --- | --- |
| Bounds | inclusive | inclusive | same (D1) |
| Order | declaration order of the cases | explicit `order()` | a fixer must not reorder history |
| Head | `null` revision | the last case of `order()` | one less special value |
| Several `was()` | one applies, total replacement, omitted args = head | **chained deltas**, omitted args = next newer shape | conversions must chain step by step for the upgrade; removes the `revision-value-gap` trap |
| Conversions | the `value:` descriptor recomputes from the model | registered conversions `down` / `up` | the request side needs the inverse |
| Requests | rules per revision (`Revised`), no translation | validation at `R` + upgrade to the head | the controller only sees the head |
| Metadata | `description()` | `#[Meta]` (description, dates) | Deprecation / Sunset headers, changelog |

## 14. Decisions

| # | Decision | Status |
| --- | --- | --- |
| D1 | Inclusive bounds for `since`, `until`, `was` | **Decided** (2026-11-11) |
| D2 | Package `ark4ne/laravel-api-revisions`, namespace `Ark4ne\ApiRevisions`, one package: pure-PHP core layers + `Laravel` layer, `illuminate/*` in `require`; the core never touches `Illuminate\*` (phpat) | **Decided** (2026-11-11) |
| D3 | Plan scope: library v1, integrations recorded as external epics | **Decided** (2026-11-11) |
| D4 | `was()` are chained deltas (omitted args = next newer shape) | **Decided** (study) |
| D5 | Explicit `order()` | **Decided** (study) |
| D6 | `down:` / `up:` only; no contextual `map:` alias | **Decided** (2026-11-11) |
| D7 | Default revision without header: `latest`; pinning through a custom resolver, available in v1 | **Decided** (2026-11-11) |
| D8 | `missing_default: 'null'` offered as an option, default `'error'` | **Decided** (2026-11-11) |
| D9 | Check discovery: classes implementing `Revisioned` under `discovery.paths` + the explicit `discovery.classes` | **Decided** (2026-11-11) |
| D10 | Revision metadata: PHP attribute `#[Meta]` on the case, read by `IsRevision::meta()` | **Decided** (2026-11-11) |
| D11 | Lock format: normalized JSON Schema (sorted keys, pretty-printed), one file per revision under `lock_path/`, committed. The runtime cache (`revision:cache`) is registered with `optimize` / `optimize:clear` | **Decided** (2026-11-11) |
| D12 | `upgrade()`: one hook called once per step with the target revision | **Decided** (2026-11-11) |
| D13 | Store / Update fragments: recipe only, no API | **Decided** (2026-11-11) |
| D14 | PHPStan extension: later, in this repository | **Decided** (2026-11-11) |
| D15 | Targets: PHP `^8.3`, Laravel `^12.46 \|\| ^13.0`, as `laravel-json-api` | **Decided** (2026-11-11) |
| D16 | Value pipeline: read → normalize to the head type → down conversions on PHP values → serialize once (§6.3) | **Decided** (2026-11-11) |
| D17 | Closures stay bound to their class and receive the subject (superseded in its signature by D18) | **Decided** (2026-11-11) |
| D18 | **One closure signature everywhere**: source, `when()`, `down:`, `up:` receive `($value, $subject)` (§3, §6.2). A `was()` never has a source: older values derive from the head value (`sourceInHistory()`) | **Decided** (2026-11-11) |
| D19 | Value errors: `strict_values` throws `ValueException`; lenient casts and reports. Default `app.debug` | **Decided** (2026-11-11) |
| D20 | `Schema::resource()` composes `Revisioned` classes, projected at the same revision | **Decided** (2026-11-11) |
| D21 | `#[Meta]` is optional and informative only (docs, changelog, headers); it never drives behaviour. `revision:lock` locks every revision but the head (`--revision=` for one) | **Decided** (2026-11-11) |
| D22 | In `query()`, renames apply to **keys** only (`filter[state]` → `filter[status]`); a change of the values (`sort=-state`, enum values) is declared like any other change, with `was()` and its conversions | **Decided** (2026-11-11) |
| D23 | A default of a field added later is written in the shape of its `since` revision, then travels through the later steps (renamed, converted) like sent input | **Decided** (2026-11-11) |
| D24 | Group `until()` vs a member's own: the member's earlier `until()` wins, a later one throws (mirror of `since`) | **Decided** (2026-11-11) |
| D25 | Every scalar pair converts automatically in both directions (PHP casts); `down:` / `up:` when the author wants otherwise; lossy-capable pairs without closure get a `conversion-lossy` **warning** | **Decided** (2026-11-11) |
| D26 | Upgrade: values normalized to the type of the shape of `X` before each up conversion (`up:` receives typed values and a snapshot of the step); `validated()` serialized back to raw values | **Decided** (2026-11-11) |
| D27 | One direction per declaration kind: responses plan / check `down`, requests `up` | **Decided** (2026-11-11) |
| D28 | Application test helpers: trait `Testing\InteractsWithRevisions` (`withRevision()`, `assertRevisionsAreConsistent()`) for the application's `TestCase`; `assertMatchesRevisionSchema()` is a `TestResponse` macro | **Decided** (2026-11-11) |
| D29 | Date formats: `format()` on the descriptor, else the configured default (`date_format` `Y-m-d`, `date_time_format` `Y-m-d\TH:i:sP`, RFC 3339). Resolved at compilation: the compiler calls `Descriptor::configure(Configuration)` and the resolved format reaches the `TypeDefinition` and `serialize()`; the core never reads configuration globally | **Decided** (2026-11-11) |
| D30 | `#[Meta]` days: `isDeprecated($now)` / `isSunset($now)` hold from the day included, read in the timezone of `$now`; the `Deprecation` / `Sunset` headers send midnight of that day in the **application timezone** (Q10) | **Decided** (2026-11-11) |
| D31 | Outside a request (jobs, console) the base revision is the configured `default` when it is a fixed revision (`latest`, `oldest`, a backed value), set by the Laravel provider; the head otherwise (a resolver class needs a request) (Q11) | **Decided** (2026-11-11) |
| D32 | Numeric strings: `int` reads digits (`'-007'`, exact to the int range) and any numeric string with an integral value exact as a float (`'12.00'`, `'1e3'`, up to 2^53); `int` and `float` refuse surrounding blanks and a trailing newline; a float written as a string keeps every digit whatever `precision` (`'0.30000000000000004'`), INF / NaN refused; an int beyond 2^53 read as float loses precision, as in PHP (Q12) | **Decided** (2026-11-11) |
| D33 | Dates: a string parsed by `DateTimeImmutable` without warning (`2026-02-30` refused, never rolled over) nor surrounding blanks, relative strings accepted, read in the default timezone unless it names one; an int timestamp in UTC. Enums: an int-backed enum reads its value as `int` does (`'3'`, `3.0`); a refused value keeps its backed value as lenient cast (Q13) | **Decided** (2026-11-11) |
| D34 | Composites (list, object): their `normalize()` only checks the container (an iterable; an array or object subject). The projection then runs every item or child through its own pipeline, in the shape of `R`; the composite's `when()` and `down:` receive that result (a list of projected items, an `array<string, mixed>` of projected children) and `serialize()` the serialized children (an empty object is `{}`). An object without source reads its children on the parent's subject (`Source::parent()`) | **Decided** (2026-11-11) |
| D35 | In lenient mode a refused list or object renders `null` (no sensible cast, as for dates) | **Decided** (2026-11-11) |
| D36 | A rule implementing `DescribesSchema` enriches the type of **any** descriptor (`Schema::rule()` and `rules()`); its keywords are merged in declaration order after those of the type, a later one wins; keywords that are not JSON-ready (scalars, arrays, nulls) throw `DefinitionException::invalidJsonSchema()`. The rule keywords apply **last, in order**, over the typed ones too: a rule giving `type: integer` on a `string()` exports `integer`, a declaration inconsistent by its author's choice; the check reports it (`schema-override`) (Q14) | **Decided** (2026-11-11) |
| D37 | `whenHas()` without key checks the source path when the source is a key or dotted path, the head declared key otherwise (closure source, parent subject); never the name at an older revision (Q15) | **Decided** (2026-11-11) |

"Proposed" decisions are applied by default until decided.
