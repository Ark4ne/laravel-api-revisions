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
- No filters, sorts or pagination in response schemas: they belong to requests (`queryParameters()`).
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

    #[Meta('Totals in cents, `state` renamed `status`', publishedAt: '2026-04-01', deprecatedAt: '2026-11-01', sunsetAt: '2027-05-01')]
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
| `Schema::resource(CustomerResource::class)` | `ResourceSchema` | another `Revisioned` class, projected at the same revision with its own shape of the same declaration kind (§6.1, D49) |

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
3. The revisions of the `was()` of a field are distinct; every one is older than the head, at or after the `since`
   and at or before the `until` (D43).
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

A type change between the **scalar pairs of the table below** needs no closure: the library converts automatically,
with PHP cast semantics. Any other pair (float ↔ bool, for instance) needs `down:` / `up:` (`conversion-missing`
otherwise, D74). The author gives `down:` / `up:` whenever the cast is not what the clients
need: **it is the author's call** (D25). Pairs that may lose information without a closure get a non-blocking
`conversion-lossy` **warning** from the check, so the risk is visible.

| Conversion | Automatic | Lossy-capable (warning without closure) |
| --- | --- | --- |
| int → float, int / float / bool → string, bool → int | yes (a float as its shortest round-trip string, a bool as `'1'` / `'0'`, D40) | no |
| string → int, string → float, float → int, int / string → bool | yes (PHP cast; string → bool as D40) | **yes** |
| backed enum → string / int | yes, via `->value` (to int only when int-backed, D41) | no |
| string / int → backed enum | yes, `::from()`; a failure is a validation error (up) or a value error (down) | no |
| nullable → non-nullable (up) | no | `default:` required |
| object, list, custom type | no: explicit closure, unless the custom type declares its conversions | — |

- On the request side, the rules of the older shape get what its type implies (`integer`, `numeric`, the enum
  values), so an automatic up conversion only sees valid input.
- Explicit casts remain available: `Cast::round()`, `Cast::floor()`, `Cast::ceil()`. A float → int conversion of a
  float outside the int range throws `ConversionException` (D44).
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
| compilation ("describe mode") | the `Probe` (or a stand-in of the class itself, D61, D79): no source, closure or condition ever runs |

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
  derived from the head value through the down chain. A `schema:` given (or holding items or children) with a source,
  a lifetime, a history, a default or a condition throws `DefinitionException::sourceInHistory()` (an object without
  source, which reads its parent's subject, is accepted). A `was()` without `name:`, `schema:`, `down:` nor `up:`
  changes nothing and throws `DefinitionException::emptyChange()`.
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
- The output keeps the order of `toArray()`; inside a nested `Schema::resource()` of a `RevisionedResource`, a native
  condition met renders nothing (only the shape's fields are projected) and one missing omits its field (D61).
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
  sorts, include, pagination) are declared the same way in `queryParameters()` (`query()` is Laravel's
  `Request::query()`, D63).

### 7.1 Flow

1. The middleware resolved the client's revision `R` (§8).
2. The rules of the shape of `R` are generated and the input is validated against them: **error keys use the names
   the client knows** (`state`, not `status`). What the rules hold: D62.
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
`missing_default: 'null'` turns the missing default of a `required()` field into `null` (D47); an optional field
without default stays absent. The default remains `'error'`.

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
    'strict_values' => null,          // value errors throw (true) or are cast and reported (false); null: app.debug (§6.3)
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

- A **chain of resolvers**: the first one returning a revision wins; an unknown value stops the chain (D57). A
  custom resolver implements `RevisionResolver::resolve(Request $request): ?Revision` (account or token pinning,
  Stripe-style); its options are named constructor arguments, the container injects the rest.
- Middleware `ResolveRevision` (alias `revision`): runs the chain, then `default`; an unknown value → **400**; stores
  the revision in the `scoped` context binding; adds the response header, `Vary` on the headers the chain reads, and
  `Deprecation` / `Sunset` from `meta()` as soon as declared (D58).
- **Path mode**: macro `Route::revisioned(fn (Router $router) => …)` adds a `{revision}` prefix constrained to the enum values and
  the `revision` middleware; the middleware removes the parameter (`forgetParameter`) and sets
  `URL::defaults(['revision' => …])`. It needs a `PathResolver` in `resolvers`, whose `parameter` names the prefix.
  Path and header modes may coexist in the chain (D60).

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
| `bound-repeated` | **warning**: `since()` or `until()` called twice on one descriptor, naming the replaced revision (§4.4 rule 5, D38) | all |
| `history-unordered` | two `was()` on the same revision, or a `was()` not older than the head | all |
| `history-before-since` | a `was()` older than the field's `since` (dead declaration) | all |
| `history-after-until` | a `was()` newer than the field's `until` (dead declaration, D43) | all |
| `conversion-missing` | a type pair neither automatic nor provided, in the direction of the declaration kind | all |
| `conversion-lossy` | **warning**: a lossy-capable automatic conversion without closure (§5) | all |
| `name-collision` | two fields under the same name in one revision | all |
| `required-since-without-default` | `required()->since(R)` without `default:` (not reported under `missing_default: 'null'`, D47) | requests |
| `schema-override` | **warning**: a `DescribesSchema` keyword overrides a keyword of the field's type (`type`, `format`, `minimum`, `enum`…) with another value (D36; with the export, D65) | all |
| `native-condition` | **warning**: a Laravel `MissingValue` / `MergeValue` in a declaration, honoured at render but outside the shape (D65) | responses |
| `eager-value` | a plain value instead of a descriptor (typed `any`, the same in every revision) | responses |
| `unknown-resource` | `Schema::resource()` on a class that is not `Revisioned`, or that does not declare the kind of its parent (D49) | all |
| `resource-absent` | a visible `Schema::resource()` field nesting a class whose lifetime (`HasLifetime`) does not cover the revision (D81) | all |
| `definition-error` | any exception thrown while building the class, with no code of its own (D39, D66) | all |
| `component-conflict` | two uses of one component name with different bodies at a revision (D70) | all |
| `lock-mismatch` | the shape of a locked revision differs from its lock | all |
| `lock-order-changed` | a locked revision moved in `order()` | enum |

Every diagnostic names the class, the field and the revision (a declaration error names the class only: the class
fails as one diagnostic). The check never throws on application code: it reports.
A declaration error thrown while building a class is caught and reported under its code (D39).

**Lock.** The real risk is not a missing `since`, it is a **forgotten** one: a new field leaks into the old revisions.
Rather than forcing `since` everywhere, the published revisions are frozen, `composer.lock`-style:

- `revision:lock` writes a snapshot (normalized JSON Schema, one file per revision) of every class at every revision
  except the head, versioned in the repository; `--revision=` locks one explicitly. `#[Meta]` plays no part (D21).
- In CI, any difference between a computed shape and the lock of a frozen revision fails the build.
- The head stays free. The lock also records `order()`, so a published revision never moves.
- A snapshot is the structure only (D69): the exported JSON Schema of each class and kind without its documentation
  (`title`, the revision `meta()` in the root `$comment`, `description`, `examples`, `deprecated`), keys sorted. Each
  `lock_path/<value>.json` also records the revision before it in `order()` (`previous`).
- `revision:lock` writes the revisions but the head that have no lock yet, never rewriting a published one;
  `--revision=` (re)writes one, the head included.
- `lock-mismatch` names each top-level field added, removed or changed (its schema or its `required`), any other
  keyword that differs, a class or a kind added (when it has fields there) or removed; `lock-order-changed` a locked revision whose previous one
  changed (moved, inserted before, removed) or which is no longer a case.

## 10. Context, Octane, performance

- The current revision is **context state, never a static variable**. `Revision::current()` reads, in order: the
  innermost `using()`, the `scoped` binding set by the middleware, the configured default (outside a request: the
  configured `default` when it is a fixed revision, the head otherwise, D31).
- **Outside HTTP** (webhooks, jobs, broadcasting, notifications) the code targets a revision explicitly:
  `ApiRevision::using($account->apiRevision, fn () => $webhook->send(OrderResource::make($order)))`. A queued job
  marked `CapturesRevision` (opt-in marker interface, D64) captures the revision **when dispatched**, not when run.
  The revision does not outlive the closure of `using()`: render inside it (a resource returned and serialized later
  renders in the outer revision), and dispatch inside it (`Job::dispatch()` returns a `PendingDispatch` that pushes
  when destroyed: `using($r, fn () => Job::dispatch())` returns it and pushes in the outer revision; write a block body).
- **Octane:** the `scoped` binding is reset on every request; no state survives a request.
- **Compilation:** the shape is computed once per `(class, revision)` and memoized. `revision:cache` (like
  `route:cache`) writes the compiled shapes of every discovered class (§9: `discovery`) to
  `bootstrap/cache/revisions.php` (D56); value closures are never serialized:
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
| `$ref` fragments | a reused object (address, money) becomes a component; its history applies everywhere (D70); a custom type declaring its identity stays in place (D82) | core |
| Changelog | diff of two consecutive revisions (additions, removals, renames, type changes), in Markdown (D71) | core |
| HTTP tests | `->withRevision(R)`, `assertMatchesRevisionSchema(R)` | Laravel / Testing |
| Contract tests | a head fixture rendered at `R`, validated against the schema of `R` | Laravel / Testing |
| Prune | `revision:prune` lists the `since` / `was` / `until` made dead by retiring the oldest revision (D73) | core / Laravel |

Later (not v1): `oneOf` polymorphism, TypeScript types per revision, a PHPStan extension checking that a source closure
returns the declared type.

## 12. Integrations and migration

The library knows neither JSON:API nor OpenAPI; two libraries build on it (`docs/specs/consumers.md`, D75).

- **`laravel-json-api` 3.0** requires it and drops its own revision model, value descriptors and value rendering: its
  hooks return `Schema` descriptors and this library renders them. A JSON:API resource is **one `Revisioned`
  response** whose top level holds one object per hook (`attributes`, `relationships`, `meta`, `documentMeta`); no
  new `DeclarationKind`. It keeps what is JSON:API: the document, relationships (a `Descriptor` of its own), `include`,
  sparse fieldsets, loading, links. Its 2.1 revision model was never tagged: no adapter.
- **`laravel-openapi-generator`** has an optional integration reading the JSON Schema export and the changelog of
  each revision (one document per revision). It never knows `since` or `was`.
- What they need from this library is epic E12, delivered before 1.0.0 (D75).

## 13. Differences with the `laravel-json-api` 2.1 model

The revision model of `laravel-json-api` 2.1 was never tagged (D75); the table records why this one differs.

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
| D19 | Value errors: `strict_values` throws `ValueException`; lenient casts and reports. Default `app.debug` (the Laravel layer; the pure core is strict, D53) | **Decided** (2026-11-11) |
| D20 | `Schema::resource()` composes `Revisioned` classes, projected at the same revision | **Decided** (2026-11-11) |
| D21 | `#[Meta]` is optional and informative only (docs, changelog, headers); it never drives behaviour. `revision:lock` locks every revision but the head (`--revision=` for one) | **Decided** (2026-11-11) |
| D22 | In the query parameters (`queryParameters()`, D63), renames apply to **keys** only (`filter[state]` → `filter[status]`); a change of the values (`sort=-state`, enum values) is declared like any other change, with `was()` and its conversions | **Decided** (2026-11-11) |
| D23 | A default of a field added later is written in the shape of its `since` revision, then travels through the later steps (renamed, converted) like sent input | **Decided** (2026-11-11) |
| D24 | Group `until()` vs a member's own: the member's earlier `until()` wins, a later one throws (mirror of `since`) | **Decided** (2026-11-11) |
| D25 | Every scalar pair of §5's table converts automatically in both directions (PHP casts; the exact list is D74); `down:` / `up:` when the author wants otherwise; lossy-capable pairs without closure get a `conversion-lossy` **warning** | **Decided** (2026-11-11) |
| D26 | Upgrade: values normalized to the type of the shape of `X` before each up conversion (`up:` receives typed values and a snapshot of the step); `validated()` serialized back to raw values | **Decided** (2026-11-11) |
| D27 | One direction per declaration kind: responses plan / check `down`, requests `up` | **Decided** (2026-11-11) |
| D28 | Application test helpers: trait `Testing\InteractsWithRevisions` (`withRevision()`, `assertRevisionsAreConsistent()`) for the application's `TestCase`; `assertMatchesRevisionSchema()` is a `TestResponse` macro | **Decided** (2026-11-11) |
| D29 | Date formats: `format()` on the descriptor, else the configured default (`date_format` `Y-m-d`, `date_time_format` `Y-m-d\TH:i:sP`, RFC 3339). Resolved at compilation: the compiler calls `Descriptor::configured(Configuration)` (its `configure()` and the schemas of its `was()`, US 5.1) and the resolved format reaches the `TypeDefinition` and `serialize()`; the core never reads configuration globally | **Decided** (2026-11-11) |
| D30 | `#[Meta]` days: `isDeprecated($now)` / `isSunset($now)` hold from the day included, read in the timezone of `$now`; the `Deprecation` / `Sunset` headers send midnight of that day in the **application timezone** (Q10) | **Decided** (2026-11-11) |
| D31 | Outside a request (jobs, console) the base revision is the configured `default` when it is a fixed revision (`latest`, `oldest`, a backed value), set by the Laravel provider; the head otherwise (a resolver class needs a request) (Q11) | **Decided** (2026-11-11) |
| D32 | Numeric strings: `int` reads digits (`'-007'`, exact to the int range) and any numeric string with an integral value exact as a float (`'12.00'`, `'1e3'`, up to 2^53); `int` and `float` refuse surrounding blanks and a trailing newline; a float written as a string keeps every digit whatever `precision` (`'0.30000000000000004'`), INF / NaN refused; an int beyond 2^53 read as float loses precision, as in PHP (Q12) | **Decided** (2026-11-11) |
| D33 | Dates: a string parsed by `DateTimeImmutable` without warning (`2026-02-30` refused, never rolled over) nor surrounding blanks, relative strings accepted, read in the default timezone unless it names one; an int timestamp in UTC. Enums: an int-backed enum reads its value as `int` does (`'3'`, `3.0`); a refused value keeps its backed value as lenient cast (Q13) | **Decided** (2026-11-11) |
| D34 | Composites (list, object): their `normalize()` only checks the container (an iterable; an array or object subject). The projection then runs every item or child through its own pipeline, in the shape of `R`; the composite's `when()` and `down:` receive that result (a list of projected items, an `array<string, mixed>` of projected children) and `serialize()` the serialized children (an empty object is `{}`). An object without source reads its children on the parent's subject (`Source::parent()`) | **Decided** (2026-11-11) |
| D35 | In lenient mode a refused list or object renders `null` (no sensible cast, as for dates) | **Decided** (2026-11-11) |
| D36 | A rule implementing `DescribesSchema` enriches the type of **any** descriptor (`Schema::rule()` and `rules()`); its keywords are merged in declaration order after those of the type, a later one wins; keywords that are not JSON-ready (scalars, arrays, nulls) throw `DefinitionException::invalidJsonSchema()`. The rule keywords apply **last, in order**, over the typed ones too: a rule giving `type: integer` on a `string()` exports `integer`, a declaration inconsistent by its author's choice; the check reports it (`schema-override`) (Q14) | **Decided** (2026-11-11) |
| D37 | `whenHas()` without key checks the source path when the source is a key or dotted path, the head declared key otherwise (closure source, parent subject); never the name at an older revision (Q15) | **Decided** (2026-11-11) |
| D38 | `bound-repeated` (a `since()` or `until()` called twice on one descriptor) is a **warning**: the last call wins, the declaration stays valid (Q16) | **Decided** (2026-11-11) |
| D39 | The check catches a `DefinitionException` thrown while building a class and reports it as an **error** under its code (`lifetime-inverted`, `since-older-than-group`, `until-newer-than-group`…; the generic `definition-error` otherwise), then goes on with the other classes: `revision:check` lists every faulty class at once. Declarations still throw at run time (Q16) | **Decided** (2026-11-11) |
| D40 | Booleans and strings: `bool → string` gives `'1'` / `'0'` (never `''`); `string → bool` reads like `filter_var(FILTER_VALIDATE_BOOLEAN)` (`'1'`, `'true'`, `'on'`, `'yes'` → `true`; `'0'`, `'false'`, `'off'`, `'no'`, `''` → `false`, case-insensitive, surrounding blanks ignored as by `filter_var`), any other string → `true` as the PHP cast; `int → bool` is the PHP cast (US 4.1) | **Decided** (2026-11-11) |
| D41 | Enums and their backing: `enum → string` always (`->value` as string, lossless); `enum → int` only for an int-backed enum (otherwise no automatic conversion: a closure is required, `conversion-missing`); `string / int → enum` reads the value as the backing type when lossless (`'3'` for an int-backed enum, `3` for a string-backed one), else `ConversionException::invalidEnumValue()` (US 4.1) | **Decided** (2026-11-11) |
| D42 | The `TypeRegistry` of custom types is **static**, like Laravel's macros: the application registers its types at boot (`TypeRegistry::register('money', MoneySchema::class)`), `Schema::__callStatic()` serves them, `TypeRegistry::flush()` empties it for tests; names are case-sensitive, as for macros; the third allowed global state (US 2.5) | **Decided** (2026-11-11) |
| D43 | A `was()` newer than the field's `until` is dead (the field no longer exists after its `until`; a `was(until)` is valid, it describes the last revision the field exists in): check code `history-after-until`, an **error**, mirror of `history-before-since` (Q17) | **Decided** (2026-11-11) |
| D44 | A float → int conversion (the automatic `FloatToInt`, `Cast::round()` / `floor()` / `ceil()`) of a float outside the int range once truncated or rounded (beyond ±2^63, `INF`, `NaN`) throws `ConversionException::outOfIntRange()`: a value error on the response side, a validation error on the request side, never a float in an `int` field nor a silent clamp (US 4.2) | **Decided** (2026-11-12) |
| D45 | `Shape` and `FieldDefinition` live in `Compilation`, not in `Definition`: a field carries its conversion chain (`Conversion\ConversionChain`, unbound, so closure-free and serializable), and `Definition` sits left of `Conversion`. `Definition` keeps the type-level model (`TypeDefinition`, `Kind`, `Lifetime`, `Direction`); the tools reading a shape (export, check, lock, Laravel, external packages) all sit right of `Compilation` (US 3.4) | **Decided** (2026-11-12) |
| D46 | When a `was(schema:)` is in force at `R`, its schema gives what describes the **value** at `R`: the type, `nullable()` and the rules; the head gives what describes the **presence**: `required()`, `sometimes()`, the conditions (`conditional`), the default and the lifetime, as a `was()` can carry no condition (§6.4). A `required()` or `sometimes()` on a `was()` schema is ignored (US 3.4) | **Decided** (2026-11-12) |
| D47 | `missing_default: 'null'` gives `null` as default to the `required()->since(R)` fields declared without `default:` only (the fields `required-since-without-default` reports, which it then no longer reports); an optional field without default stays without one (absent for older clients). A non-nullable field receives `null` too: opting in accepts that the controller gets `null` from older clients (the nullable-only variant was rejected). Resolved by the `ShapeSelector` from the `Configuration`, recursively in objects and lists (US 3.5) | **Decided** (2026-11-12) |
| D48 | The `Revisioned` contract is static and per declaration kind: `declarationKinds(): non-empty-list<DeclarationKind>` (a resource `[Response]`, a request `[RequestBody, RequestQuery]`: `body()` and `queryParameters()` on one `FormRequest`, D63) and the bridge `describeRevisioned(DeclarationKind, mixed $probe): array<string, Descriptor>`, in which the class builds itself on the probe (only it knows its constructor). A shape is compiled per `(class, kind, revision)` and names its class and kind (US 5.1) | **Decided** (2026-11-12) |
| D49 | A nested resource (`Schema::resource(Other::class)`) is the shape of `Other`'s declaration of the **same kind** as the declaration it is nested in, at the same revision: a resource in a response, a body in a body, a query in a query, so its direction is the parent's. Its type references the class (`TypeDefinition::$resource`), never copies its shape (cycles compile); the compiler refuses a nested class that does not declare that kind (`DefinitionException::undeclaredKind()`, US 2.6) | **Decided** (2026-11-12) |
| D50 | The bridge `describeRevisioned($kind, $subject)` builds the class on its subject: the `Probe` at compilation, the value of a nested resource (`Schema::resource()`) at projection, so the closures of the nested declaration reach their own subject; called by the compiler, the projector and the upgrader (the value of a nested resource, D55) (amends D48, US 6.2; public since D79) | **Decided** (2026-11-12) |
| D51 | An item of a list whose condition fails (`when()`, `whenHas()`) is dropped and the list re-indexed, as a field is omitted from an object; a `whenHas()` without key on an item always holds (the item is the element itself) (US 6.2) | **Decided** (2026-11-12) |
| D52 | A composite whose shape at `R` comes from a `was(X, schema:)`: the projection reads the **head** children (items, resource) where the head is still in force, `X->next()` for the newest such `was()` (`R` without one), each with its own history; its `when()` / `down:` receive them projected, so a conversion starts from the shape it is planned from; the `was()` schema is the type at `R` (rules, export) and serializes the result. Up, the value converted by `up:` is in that shape too. `FieldDefinition::$children` / `$items` are those of the head at `FieldDefinition::$headAt` (US 6.2, changes US 3.4) | **Decided** (2026-11-12) |
| D53 | Value errors, lenient: a refused value renders its PHP cast **as it is** at `R`, without `when()`, down chain nor serializer, so no closure ever sees a value outside its type (a list or object `null`, D35; an unknown enum its raw value); a down conversion that fails renders `null` (no sensible cast between two types); a non-nullable field or item resolving `null`, or given `null` by a `down:`, renders `null`; each error goes to the reporter once, never the value in its message (its stack trace holds arguments unless `zend.exception_ignore_args` is on, the production default). The pure core is strict by default (`Configuration::$strictValues = true`); the Laravel layer gives `app.debug` (US 6.4) | **Decided** (2026-11-12) |
| D54 | Upgrade details (US 7.1): the upgraded input is in the **head order**, keys the shape does not know kept last (the validator of the Laravel layer has already removed them); only the values an `up` step converted or a default wrote are serialized back by the head descriptor, the others stay as sent (D26); a field retired after `X` gets no rename of `was(X)` and is dropped after the hook of `X → X->next()`; a value the type in force at `X` refuses throws `ValueException::refused()` (the input was validated: a gap between the rules and `normalize()`); a failed conversion throws its `ConversionException` (Q18); a retired field never takes a name from a newer field: its value is set aside during the renames of its last step and put back for the hook only under a name no newer field took, and a default of a field added in `X->next()` replaces it; the snapshot an `up:` closure receives and the input the hook receives hold the values **as sent**, except those an earlier step converted (PHP values of their type), so only the converted value itself is guaranteed typed; a converted or defaulted value the hook replaces is serialized too (`serialize()` passes a raw value through); only a request shape (up) is upgraded (`DefinitionException::notAnUpShape()`) | **Decided** (2026-11-12) |
| D55 | Nested upgrade (US 7.3): each step `X → X->next()` runs on every level in the same pass — the request, the children of an object, each item of a list, a nested resource — so the hook of `X->next()` sees the whole input in the shape of `X->next()`. A composite whose chain converts at `X` is converted whole (its `up:` receives the value in the shape of `X` and returns it in the shape of `X->next()`, D52); otherwise, where its head descriptor is in force (`headAt` at or before `X`), its children, items or resource take the step: renames, conversions, defaults of the children added in `X->next()`, children retired dropped; before `headAt` the value is in the shape of a `was()` schema and waits for its conversion. A nested resource upgrades in the shape of its class for the declaration kind of the request at `headAt`, its descriptors built on its value (D49, D50); only the request's hook is called (decided with the maintainer). Serialization stays at the end (D54, decided with the maintainer): the values converted or defaulted are tracked by their path of head keys and serialized by their head descriptor; a list is tracked whole (the hook may filter or reindex it); a composite converted or defaulted is serialized deep, each child or item by its head descriptor, never by the composite's own `serialize()` (an empty one stays an array), as its `up:` or default may hold typed children; every `serialize()` passes a raw value through, so serializing an untouched value changes nothing; a value the hook replaced keeps what the hook returned when it is no array; a resource on one side only (descriptor vs shape) throws `missingDescriptor()` like any composite. The snapshot an item's `up:` receives is that of the level holding the list; a child's, that of its object. Nested levels keep the order the steps write; the request is put in the head order | **Decided** (2026-11-12) |
| D56 | Compiled shapes file (US 5.3): a PHP file returning `class@kind@revision → serialize(Shape)` (`var_export` of strings, so OPcache keeps it in shared memory and a shape is unserialized only on its first use), stamped with a format version (a file of another release of the library, or corrupted, throws `CacheException::invalidFile()`: run `revision:cache` again), written to a temporary file then renamed. `revision:cache` compiles every declaration kind of every discovered class (`discovery.paths` + `discovery.classes`, D9) at every revision with a fresh compiler, and fails on the first declaration error, keeping the previous file; a class missing from the file is compiled at run time. `Shapes` reads the file before compiling; `flush()` keeps it. A stale file (declarations, date formats, custom types changed) is the application's responsibility, as for `route:cache` | **Decided** (2026-11-12) |
| D57 | Resolvers (US 8.2): a resolver returns the revision a request asks for, `null` when it asks for none, and throws `UnknownRevisionException` (400, US 8.3) when it names none: an unknown value **stops the chain**, it never falls through to the next resolver or the default. The built-in resolvers read one string: absent or empty is no revision; it matches a **backed value exactly** (case-sensitive, no trimming, never a case name; an int-backed enum reads digits; a case of the enum, as a bound route parameter gives, is kept, and an int compares as its digits), anything else — an array query parameter included — is unknown, the accepted values listed oldest first. Each resolver is built by the container, its options passed as named constructor arguments; the chain is a `scoped` binding. When no resolver answers, the `default`: a fixed revision, or a resolver class run last whose `null` gives the head. `resolvers` defaults to the header resolver (`Api-Revision`) | **Decided** (2026-11-12) |
| D58 | `ResolveRevision` (US 8.3, alias `revision`): the revision becomes the base of the request's scope; the response gets `response_header` (its backed value), `Vary` appended with the headers of the chain's resolvers implementing `VariesOn`, on the 400 too (none in query or path mode: the URL differs; a custom resolver reading headers declares them, D59), `Deprecation: @<Unix time>` (RFC 9745) and `Sunset: <HTTP-date>` (RFC 8594) **as soon as `deprecatedAt` / `sunsetAt` are declared**, even in the future (the RFCs announce them), at midnight of the day in the application timezone (D30; UTC without one). An unknown revision is answered **400** by the middleware itself, never reported: JSON `{message, revision, accepted}`, the value cut to 64 characters, invalid UTF-8 substituted. The alias `revision` is registered only when the application has none of that name | **Decided** (2026-11-12) |
| D59 | `Vary` of custom resolvers (Q19): a resolver reading request headers implements the optional contract `Laravel\Http\VariesOn` (`headers(): list<string>`); the chain collects the headers of every resolver implementing it, the default included, each once, and the middleware adds them to `Vary`. `HeaderResolver` implements it; a resolver without it adds nothing | **Decided** (2026-11-12) |
| D60 | Path mode (US 8.4): `Route::revisioned(fn (Router $router) => …)` groups its routes under the prefix `{parameter}`, the `parameter` option of the first `PathResolver` of `resolvers` (`revision` by default; without a `PathResolver` the macro throws `ConfigurationException`: the path would never be read), constrained to the backed values of the enum (any other path matches no route: 404), with the `revision` middleware (Laravel runs a middleware once per route, so a group already holding it is fine). After resolving, the middleware forgets the parameters of the chain's `PathResolver`s from the route (no controller receives them) and sets them as URL defaults to the resolved revision, on every request it handles, so a request resolved by header generates path URLs in its revision too. A `PathResolver` parameter name is reserved for path mode: any route using it gets the revision as URL default. The constraint is built from `order()` when the routes are registered: with `route:cache`, a new revision needs `route:cache` again. A `PathResolver` class as `default` counts as one (parameter `revision`). PHPStan knows the macro through `extension.neon` (`RevisionedRouteExtension`) | **Decided** (2026-11-12) |
| D61 | `RevisionedResource` (US 8.5): at compilation the resource is built on a permissive subject (`DescribeSubject`: every read is `null`, no attribute, no relation loaded, the subject never throws) and an empty `Request` (the shape never depends on the request served) instead of the `Probe`, so that the plain values and native conditions of `toArray()` evaluate; the descriptors stay lazy (an eager capture inside a descriptor closure is no longer caught at compilation for resources). A plain value failing there (`$this->placed_at->format()` on `null`) throws `DefinitionException::failedDescribing()`: plain values must be null-safe. A plain value is a field of the shape, typed nullable `any` (`Schema\EagerSchema`), always conditional (the render may omit it, below), the same in every revision (`eager-value`). A `MissingValue` / `MergeValue` there stays out of the shape (`native-condition`); at render, a native condition is honoured as `JsonResource` honours it and its value rendered as it is; a field of the shape that is a missing native condition, or a key `toArray()` left out on this subject, is omitted (top level and nested resource alike). The output follows the order of `toArray()`; `Arrayable` values (any, eager) are rendered as arrays. `->whenLoaded('a.b')` is a presence condition (`Presence::loaded()`): the `SourceReader` decides, `relationLoaded()` per segment on an Eloquent model (never a lazy load), `has()` on any other subject. `SourceReader` is an interface: `PlainSourceReader` in the core, `EloquentSourceReader` in Laravel (also `peek()` from `getAttributes()` / loaded relations and `has()` on attributes present or relations loaded, as Laravel's `whenHas()`) | **Decided** (2026-11-12) |
| D62 | Validation rules of a request shape (US 8.6): one entry per field visible at the client's revision, under its name there (`a.b` for children and nested resources, `a.*` for items); the fields added later are never validated. Order: `sometimes`, `required`, `nullable` **as declared** (their combination is Laravel's: `required` still refuses `null`; below a parent the client may leave out or send `null` — not required, `sometimes`, nullable, or under such a parent; an item only when nullable, a wildcard expanding to the items sent — a `required` child becomes `required_unless:<parent>,null`: required as soon as the parent is sent, `{}` included, where `required_with` would take `{}` for absent), the rules of the type at the revision (string `min:`/`max:`/`regex:` and the known formats: `email`, `uri` → `url`, `uuid`, `ipv4`, `ipv6`, `date` → `date_format:Y-m-d`, `date-time` → `date`, another format none; `integer` / `numeric` with `min:`/`max:`; `boolean`; a date `date_format:` of its format, the wire format the export documents; an enum Laravel's `Enum` rule; `list` / `array` with `min:`/`max:`; nothing for `any` and a custom type, whose descriptor declares its rules), then the rules of the **first** up conversion only (the one the client's value goes through: `Converter::extraRules()`, plus `in:` the values of an enum it converts to; an explicit `up:` adds none), then the author's `rules()` of the descriptor in force at the revision, as given. Where the schema of a `was()` is in force, its children and items are read from that schema and the field's type, never from the head (US 6.2). Two fields under one name merge their rules (the check reports the collision) | **Decided** (2026-11-12) |
| D63 | `RevisionedRequest` (US 8.7, Q18): a `FormRequest` declares its body in `body()` and its query parameters in `queryParameters()` (`query()` is Laravel's `Request::query()`), both empty by default. `rules()` returns the rules of both shapes at the current revision (D62); a name both declare at that revision, or at the head (their heads are merged), throws `DefinitionException::declaredTwice()` (Laravel validates `all()`, where the body hides the query). Once the rules pass, a validator `after` hook registered last (the application's own hooks run first) splits the validated input by the names of the query shape and upgrades each part to the head (D54, D55); the request's `protected upgrade()` hook upgrades the body only. `validated()` / `safe()` return the body and query heads merged; `input()` / `all()` stay the input as sent. A value the upgrade rejects fails the validation on the client's key (its name at the client's revision, `a.b`, `a.0`): the upgrader throws `InvalidInputException` (the key, and the reason given) for a `ConversionException` of a converter or a closure and for a `ValueException` (a value the rules let through and the type refuses). A closure rejects an input with `ConversionException::invalid($message)`, whose message is the error; any other rejection gets the message of the `in` rule (`validation.in`, custom messages and attribute names honoured, the rule recorded in `failed()`; a reason is an error message only). A value the client never sent (a default, a field or composite added after its revision) has no client key: its rejection stays the exception itself, a declaration error. `ConversionException::missing()` stays a declaration error (500), as does any other exception of a closure (amends D54) | **Decided** (2026-11-12) |
| D64 | Outside HTTP (US 8.8): a queued job (`ShouldQueue` job only: a queued notification, mailable, listener or broadcast is wrapped by Laravel and not captured; dispatch a marked job that sends it) opts in by implementing the marker interface `Laravel\Queue\CapturesRevision` (other jobs run in the base revision: the configured fixed default, else the head). At dispatch the payload stores the backed value of `current()` (`ark4ne:revision`) and its handler is wrapped, so the job (`handle()`, its middleware, `failed()`) runs inside `using()` of that revision, the `sync` queue included; a chained job marked too captures the revision of the job dispatching it. A stored value the enum no longer knows (a revision pruned between dispatch and run) fails the attempt with `UnknownRevisionException`; `failed()` then runs in the base revision | **Decided** (2026-11-13) |
| D65 | Check rules (US 9.2), decided with the maintainer: `native-condition` is a **warning** (the native condition is honoured at render, D61; it only escapes the shape, the export and the lock), checked in the Laravel layer (the bridge drops `MissingValue` / `MergeValue` before the core sees them); `schema-override` moves to US 10.1, which compares the keywords the exporter derives from the type with `TypeDefinition::$jsonSchema` (no second mapping of types to keywords in the check) | **Decided** (2026-11-13) |
| D66 | Any exception thrown while building a declaring class for the check (its descriptors, its shapes at every revision), not only a `DefinitionException`, is reported as an **error** — under the code its `DefinitionException` carries, else `definition-error` — and the check goes on with the next class: the check never throws on application code (§9). Built first, before the rules run on the class; a class that fails is reported once and its rules are skipped. An invalid `order()` stops the check after the enum: no class can be compiled against it | **Decided** (2026-11-13) |
| D67 | JSON Schema export (US 10.1), decided with the maintainer: objects stay **open** (no `additionalProperties`: a schema states what is guaranteed; a resource still renders its native conditions); a nested resource is `$ref: '#/$defs/<Class>'` (the class name, `\\` written `.`; `'#'` for the class exported), each `$defs` entry the shape of that class for the same kind at the same revision, flat, so cycles export; a date in a format other than `Y-m-d` / RFC 3339 is a `string` with a `pattern` derived from its PHP format when every token is numeric (`d`, `j`, `m`, `n`, `Y`, `y`, `H`, `G`, `i`, `s`, `v`, `u`, `P`, `O`, escaped literals), else none, its PHP format in `$comment`; `revision:export {--revision=} {--class=}` prints JSON on stdout, `{revision: {class: {kind: schema}}}` narrowed by its options, the schema alone when one revision, one class and a single kind remain. Applied with it: one document per class, kind and revision (`$schema` 2020-12, `title` the class, `$comment` the kind, the revision and its `meta()`: description, published, deprecated and sunset days; `#[Meta]` never changes a keyword, D21); `required` lists the fields a client always sees — a response field unless conditional, a request field `required()` without `sometimes()` —, children and items alike; a nullable field adds `null` to its `type` (and to its `enum`); a custom type, a nested resource or a schema without a string `type` is wrapped in `anyOf` with `{type: null}`; `any` stays `{}`; keywords: `minLength`/`maxLength`/`minimum`/`maximum`/`minItems`/`maxItems` from `min`/`max`, `format`, `pattern`, `enum` with the backing `type`, `description`, `examples`, `deprecated`, a custom type's fragment, then the rule keywords last (D36). Where the head is in force at `R` the children and items are read from the field definitions (their own presence and nullability), where a `was()` schema is, from the type at `R` (typed, never required) | **Decided** (2026-11-14) |
| D68 | Export review (US 10.1), decided with the maintainer: a `pattern()` (PCRE with delimiters, as Laravel's `regex:` takes it) is exported without its delimiters (`/^A-/` → `^A-`, bracket delimiters too) when its flags are none, `u` or `D`; with any other flag (`i`, `x`, `s`, `m`…) or no valid delimiter, no `pattern`, the PCRE in `$comment`. The children and items of a composite carry their nullability on their `TypeDefinition` (`TypeDefinition::$nullable`, set by `ObjectSchema` / `ListSchema` for their parts; `false` on a field's own type, whose nullability stays on `FieldDefinition`), so the children of a `was()` schema export it; conversion plans never compare it (`ConversionChain` compares values, not presence) | **Decided** (2026-11-14) |
| D69 | Lock (US 9.4), decided with the maintainer: a snapshot freezes the **structure** only — the exported JSON Schema without its documentation keywords (`title`, the root `$comment` with `meta()`, `description`, `examples`, `deprecated`; the `$comment` of a date format or a PCRE stays), keys sorted — so deprecating or re-describing a head field never breaks a lock. `revision:lock` writes only the missing locks of the revisions but the head; `--revision=` (re)writes one explicitly, the head included. Each `<value>.json` records `previous`, the revision before it in `order()` (`null` for the oldest): `lock-order-changed` when it differs today or the case is gone. `lock-mismatch` is reported per top-level field (added, removed, schema or `required` changed), per other differing keyword, and per class or kind added to or removed from a locked revision — a class or kind added is reported only when it has fields at that revision (a new resource whose fields all have a later `since()` leaks nothing, so nothing is to declare: refined after the review, confirmed by the maintainer) | **Decided** (2026-11-14) |
| D70 | Components (US 10.2), decided with the maintainer: a component is a **custom type**, under the name its `TypeDefinition::custom()` declares (`money`), or an **object the author names**, `Schema::object([...])->component('Address')`; nothing is guessed. It lives in the `$defs` of each document using it, once, referenced `#/$defs/<name>` (JSON Pointer escaped) beside the nested resources (D67): every document stays self-contained, a generator lifts them by name. Its body at `R` is its type at `R` (an object: its children with their own presence where the head is in force, typed only under a `was()` schema), so its history applies in every reference. Two uses of one name with different bodies in one document are a declaration error — so is one component used both where the head is in force and inside a `was()` schema at a revision (presence on one side, typed only on the other), as decided: the export throws `DefinitionException::componentConflict()` and the check reports `component-conflict` (error, the field and the revision). `typeKeywords()` (read by `schema-override`) stays inline | **Decided** (2026-11-14) |
| D71 | Changelog (US 10.3), decided with the maintainer: for each revision `R` after the base, what `R` changes against the revision before it, per class and declaration kind: a field is matched across both shapes by its **head key path** (so a `was(name:)` is a rename, never a removal plus an addition), walked through object children and list items (`customer.name`, `lines.*.sku`, the names at `R`); a nested resource is not entered (its class has its own section). Changes: added, removed, renamed, **type changed** when the keywords its type gives (`JsonSchemaExporter::typeKeywords()`: kind, constraints, format, enum, nullability; never documentation) differ — shown as compact JSON before → after; for a composite whose children or items are walked on both sides, only its own keywords —. The "now required / now optional" line first proposed is left out: the presence of a field comes from its head (D46), so it never changes between two revisions; the children typed only under a `was()` schema are compared by their type, never walked. Markdown, newest revision first, each section headed by its `meta()` (description, published / deprecated / sunset days); a revision without change says so. `revision:changelog {--from=} {--to=}`: `--from` is the base, excluded (the oldest by default), `--to` included (the head by default) | **Decided** (2026-11-14) |
| D72 | Test helpers (US 10.4, D28), decided with the maintainer: the test assertions validate against the export of the revision with **closed objects** (`JsonSchemaExporter` built `closed: true` adds `additionalProperties: false` to every declared object — the root, children, nested resources and object components, never a custom fragment): a field leaking into a revision fails a test, the published export stays open (D67). `TestResponse::assertMatchesRevisionSchema(string $class, ?Revision $revision = null)` (registered by the trait's `setUpInteractsWithRevisions()`): the revision defaults to the response header (`response_header`), the document is `data` when the body holds it (a `JsonResource` wrap), else the body; a list validates each item. `withRevision(Revision)` uses the first resolver of the chain that can carry it: `HeaderResolver` → a default header, `PathResolver` → `URL::defaults()` of its parameter (for `route()`), `QueryResolver` → its parameter added to every request of the test (the trait overrides `prepareUrlForRequest()`); a custom resolver alone throws a `LogicException`. `assertRendersAtEveryRevision(string $class, mixed $subject, ?list<Revision> $revisions = null)` projects the response of `$class` on `$subject` at each revision through the application's `Projector` and validates it the same way. `opis/json-schema` stays a dev dependency of the package and is suggested: without it the assertions throw a `LogicException` naming it | **Decided** (2026-11-14) |
| D73 | Prune (US 10.5), decided with the maintainer: `revision:prune` lists what retiring the oldest revision `P` makes dead, for every discovered class and declaration kind, walked through object children and list items (head key paths): an `until(P)` — delete the field (its children, items and `was()` are not listed again) —, a `was(P)` — delete that `was()` —, a `since(P)` — delete the `since()`, already a no-op (a group's bound is listed on each member). A `since(P->next())`, a no-op once `P` is gone, is not listed. Each entry gives a location when reflection can tell: the file of the class and the line of the field key (`'key' =>`, a nested path matched key after key) among the tokens of its declaring methods (`describeRevisioned()`, and in Laravel `toArray()`, `body()`, `queryParameters()`) declared in the class's own file, preferring the match whose expression names the bound (`::P`), else the first line of the first declaring method, else the file alone. Informative: the command always exits 0 (`Nothing to prune.` when nothing is), a class that fails to build is reported as a warning line and skipped (`revision:check` stays the gate) | **Decided** (2026-11-14) |
| D74 | Q20, decided with the maintainer: the automatic conversions are exactly the scalar pairs of §5's table; any other pair (float ↔ bool) needs `down:` / `up:` and is reported `conversion-missing` without them. D25 is read with this list | **Decided** (2026-11-14) |
| D75 | Consumers (`docs/specs/consumers.md`), decided with the maintainer: `laravel-json-api` 3.0 requires this library and delegates the rendering of its values to it (one `Revisioned` response per resource, one object per hook, no new `DeclarationKind`); `laravel-openapi-generator` integrates optionally through the export and the changelog. Every requirement R1, R2, R4–R10 (then R12, R13) of that document is epic E12, delivered **before 1.0.0** (CQ1); R3 was dropped | **Decided** (2026-11-14) |
| D76 | Class lifetime (R2, CQ2, CQ3), decided with the maintainer: an optional interface on a `Revisioned` class gives its `Lifetime` (no attribute read by reflection; a consumer maps its own attributes to it); a class absent from a revision is left out of that revision's lock and export, and its appearance and disappearance show in the changelog; the details (what `Shapes` and the check do with an absent class) are decided in US 12.3 | **Decided** (2026-11-14) |
| D77 | Projection plan (R9), decided with the maintainer: the projection of a class, kind, revision and set of fields is compiled once into a plan cached per process (sources, presences, conditions, bound chains, configured serializers); the **declaration** (`toArray()`) is run once **per render**, not per subject: a collection runs it once on a template instance whose `resource` is set to each subject, so a closure capturing the request always sees the request being served (Octane included). The declaration is never cached across renders (amended by D86: the plan is built per render, only the `Shape` is cached per process) | **Decided** (2026-11-14) |
| D78 | Plain values under the plan (CQ5), decided with the maintainer: a resource whose `toArray()` holds a plain value (`EagerSchema`, D61) or a native condition keeps the per-subject path (one `toArray()` per subject, as before D77): slower, compatible, and already reported by the check (`eager-value`, `native-condition`) | **Decided** (2026-11-14) |
| D79 | `Revisioned` implemented by a consumer (US 12.1, consequence of D75): the bridge `describeRevisioned()` is public, no longer `@internal` (amends D48, D50); a consumer may call it to project its own class, and may build its declaration on its own stand-in instead of the `Probe` it receives, as `RevisionedResource` does with `DescribeSubject` (D61): it then loses the detection of an eager read at compilation, and must still run no source, closure or condition while declaring. `revision:prune` locates fields in the declaring methods only (D73): a field declared in a consumer's own hook points at `describeRevisioned()` | **Decided** (2026-11-14) |
| D80 | Without configured enum (US 12.2, R5), decided with the maintainer: `revisions.enum` left `null` selects the package's single-case enum `Revisions\Unrevisioned` (`Head = 'head'`), so an application declaring no revision still renders its resources, validates its requests and runs every command through the library, with no history possible. The library stays invisible to its clients: the `revision` middleware resolves nothing (no resolver runs, a header sent is ignored, never a 400) and adds no `Api-Revision`, `Vary`, `Deprecation` or `Sunset` header; a queued job captures no revision. The commands work on the one revision: `revision:cache` compiles its shapes (so `optimize` succeeds), `revision:lock` locks nothing, even with `--revision` (the head stays free), `revision:prune` has nothing to prune, `revision:changelog` no change to list. A `default` other than `latest` / `oldest` without enum is a `ConfigurationException`, as any wrong value; `Route::revisioned()` and `withRevision()` throw, naming the missing enum; `assertMatchesRevisionSchema()` validates against the one revision. A job captured while an enum was configured runs in the implicit revision once it is removed (not `UnknownRevisionException`, D64: the library stays invisible) | **Decided** (2026-11-14) |
| D81 | Class lifetime details (US 12.3, D76), decided with the maintainer: a `Revisioned` class implementing `Schema\HasLifetime` gives `public static function revisionedLifetime(): Lifetime` (static like `declarationKinds()`; `Lifetime::since(R)`, `Lifetime::until(R)` write one bound, inclusive like a field's); `Schema\ClassLifetime::exists($class, $revision)` says whether a class exists at a revision (any class without the interface: always). A class absent at `R` has no shape there: compiling it throws `DefinitionException::classAbsent()` (serving it is a programming error: the application, or a consumer, answers 404 from `exists()`); the check, the cache, the export and the lock skip it at `R`; the changelog lists the class added in its first revision and removed in the one after its last. A visible `Schema::resource()` field nesting a class absent at its revision is the error `resource-absent`. A locked revision holding a class now absent there is a `lock-mismatch` (a published revision changed) |
| D82 | Custom types with identity conversion (US 12.4, R4; amends D70), decided with the maintainer: a custom type declares in its `DeclaresConversions::conversions()` that its values do not depend on its fragment, `$converters->identity('<name>')` (both directions). Two types of that name differing only by their fragment (same kind and name, no items, children nor resource) then hold the same values: `ConversionChain::withStep()` plans no step, so no `conversion-missing`; an explicit `down:` / `up:` still applies. The fragment change stays a change of the export, the lock (`lock-mismatch`) and the changelog (type changed). Its fragments varying by instance, such a type is exported **inline** (its fragment in place, no `$defs` entry), so two of its instances in one revision never make a `component-conflict`; other custom types keep their component (D70). Without `identity()`, a fragment change is a missing step, as before | **Decided** (2026-11-14) |
| D83 | Checks contributed by a package (US 12.5, R6), decided with the maintainer: a package (or an application) tags its `Checking\Check` classes in the container under `RevisionsServiceProvider::CHECKS` (`'revisions.checks'`), `$this->app->tag([...], RevisionsServiceProvider::CHECKS)`. The `Checker` bound by the provider resolves them through the container (constructor injection) and runs them after the built-in and lock checks, in tag order, for `revision:check` and `assertRevisionsAreConsistent()` alike. No configuration key | **Decided** (2026-11-14) |
| D84 | Export as a public contract (US 12.6, R7), decided with the maintainer: the discovered classes with their declaration kinds are listed by the final class `Checking\Declarations`, built from a `ClassFinder` (bound in Laravel): `declarations()` gives `array<class-string, list<DeclarationKind>>` in the finder's order, `at(Revision)` the same restricted to the classes existing at that revision (D81). The `ClassFinder` interface is unchanged; `revision:check --list` keeps printing the class names only. `JsonSchemaExporter::export()` / `objects()`, the structure of `revision:export`, `Changelog::changes()` / `markdown()` with `Change` / `ChangeKind`, `ClassFinder` and `Declarations` are named public contract in `architecture.md` §4, under semver from 1.0. | **Decided** (2026-11-14) |
| D85 | Compiled shapes file read lazily (US 12.7, R8; amends D56), decided with the maintainer: `CompiledShapes::load()` checks only that the file returns an array stamped with the current format; each entry is checked when its shape is first read (`get()`), a value that is not a serialized `Shape` (`null` included) throwing `CacheException::invalidFile()` then, while the other shapes are still served. A request pays the `require` (OPcache keeps the array in shared memory) and, per shape asked, one array read and one `unserialize()` loading the shape model only, never the declaring class nor a descriptor | **Decided** (2026-11-15) |
| D86 | Projection plan details (US 12.8, R9; clarifies D77, D78), decided with the maintainer: (1) the public entry point is `Projector::plan(Shape, descriptors, ?keys): ProjectionPlan`, run per subject by `ProjectionPlan::run($subject)`; everything resolved ahead (sources, presences, conditions, bound chains, configured serializers, nested object and list plans); `keys` keeps those head keys of the top level, in the head order (a key not in the shape is ignored); `Projector::project()` is `plan()->run()`, one pipeline. A nested resource is still declared per value (its `describeRevisioned()` receives its subject). (2) The plan is built **per render** from the declaration of that render; only the `Shape` is cached per process (D48): a plan holds the closures of its declaration and is never kept across renders. (3) The render boundary is the collection: `RevisionedResource::collection()` returns a `RevisionedResourceCollection` whose `toArray()` runs `toArray()` once on a template instance (built on the first subject), builds one plan, then sets the template's `resource` to each subject and runs the plan; a single `resolve()` runs its own `toArray()` once, as before; a custom `ResourceCollection` keeps the per-item path. (4) The per-subject path (one `toArray()` per item) is kept when the class reports a native condition (D65), when the template's declaration holds a plain value or a native condition or lacks a key of the shape, or when the class opts out with `public static function revisionedPerSubject(): bool` returning `true` (default `false`). (5) Rule, documented: under the plan, `toArray()` depends on the subject only through the closures and conditions of its descriptors; a structure decided in plain PHP on `$this->resource` (an `if`, a ternary choosing the descriptor) applies the template's choice to every item, unless the class opts out; so does a native `$this->when(condition, descriptor)` met at compilation (it returns the descriptor on the template); a collection given resources built beforehand renders them through a fresh template, so their own instance state is not read (amended by D89: `keys` may hold dotted paths of head keys) | **Decided** (2026-11-15) |
| D87 | Macros on descriptors (US 12.9, R10), decided with the maintainer: a static registry `Schema\DescriptorMacros` (not `TypeRegistry`, which registers types; not `Descriptor::macro()`, which every custom type would inherit), filled at boot with `DescriptorMacros::register($name, Closure)`; `Descriptor::__call()` serves it, so `Schema::string()->whenInFields('title')` calls the closure with the descriptor first, then the arguments given (positional or named): `($descriptor, ...$arguments)`. The closure is not bound: it builds with the public modifiers only. It must return an instance of the receiver's class (`DefinitionException::invalidMacroResult()`), so PHPStan types the call as the receiver. A name is a PHP method name that `Descriptor` does not declare (`invalidMacroName()`), case-sensitive; a **public** method of the subclass of that name wins over the macro (a private or protected one does not, as for any `__call()`); an unregistered name throws `unknownMacro()`. `flush()` empties it for tests only: the registrations carry no volatile data (Octane included). PHPStan: `apiRevisions.macros` maps each name to a public static method `Class::method` (the one registered, `Class::method(...)`): the extension declares the macro on every descriptor with that method's parameters after the first, returning the receiver's type. The fourth allowed global state | **Decided** (2026-11-15) |
| D88 | Property read of the core `SourceReader` (US 12.10, R12 of `laravel-json-api`), decided with the maintainer: `PlainSourceReader` reads a public property **exactly as `get_object_vars()` from outside lists it**, without building the object's property table on the common paths (`get_object_vars()` builds it on every object read, an Eloquent model included). The declared properties of a class (its parents included) are listed once by reflection, per reader instance (it depends only on the code; a `Memo` closure per read cost more than `get_object_vars()`): an initialized declared public non-static concrete property is read directly; a name no class declares is found through `property_exists()` when the class has no internal parent but `stdClass`. Every other case asks `get_object_vars()` (rare: a property not initialized, unset or lazy, a non-public, static or virtual one of that name, a class with an internal parent such as `SimpleXMLElement` or `ArrayObject`), then `__get()` / `__isset()` / `ArrayAccess` as before. Same outputs as before: an uninitialized typed property is not held and falls back, a lazy object (PHP 8.4) is initialized, a hooked property reads as listed; `peek()` never calls a magic getter | **Decided** (2026-11-16) |
| D89 | Paths in a projection plan (US 12.11, R13 of `laravel-json-api`), decided with the maintainer: the `$keys` of `Projector::plan()` may hold dotted paths of **head keys** at every level (the caller translates names at the revision). `address` keeps the field whole; `address.city` keeps `address` with only its child `city`; several paths keep the union of their branches, and a shorter path wins (`address` with `address.city` keeps `address` whole). A member left out is never evaluated: its source, presences (`whenHas()`, `when()`), normalization, conversions and value errors do not run; the object's own source and conditions run once, its kept children are output in the head order under their names at the revision. `*` goes through the items of a list (`tags.*.name` keeps `name` in each item). A path going on into a field whose members are not planned one by one keeps that field whole: an object or a list with a conversion chain or a `was(schema:)` in force at the revision (its conversion needs every member), a list through a segment other than `*`, a nested resource, a scalar. A list is kept item by item only when every path into it goes through `*`; one through a key keeps it whole, in a union too. A `keys` entry equal to a head key of the top level keeps that field whole, a dot in it included (as before paths); a child whose key holds a dot cannot be reached by a path. A segment naming no child of an object at the revision is ignored, as a top-level key is; a field all of whose paths are ignored is left out. `null` keeps everything, an empty list nothing | **Decided** (2026-11-16) |

"Proposed" decisions are applied by default until decided.
