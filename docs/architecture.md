# Architecture, concepts and patterns

This document sets the concepts and patterns of `ark4ne/laravel-api-revisions`. They follow those of
`ark4ne/laravel-json-api` 2.x and `ark4ne/laravel-openapi-generator` (models for the architecture and the tooling,
never dependencies). Every contribution follows them; a deviation is discussed and recorded here **before** it is
coded. The behaviour (what the library does) is specified in [`specs/model.md`](specs/model.md); this document says
**how the code is organised**.

**Guiding idea:** the class **declares** the head, the shape of each revision is **compiled once**, the projector
**walks down**, the upgrader **walks up**, the exporter **describes**. No layer does the work of another: a descriptor
does not know any revision but its own history, the projector never decides what is visible, the Laravel layer never
computes a shape.

## 1. Lifecycle

```
                   ┌────────── once per (class, kind, revision), memoized / cached ──────────┐
Declaring class ─► │ Probe ─► toArray() / body() / query() ─► descriptors (head + history)   │
                   │                                  └─► ShapeSelector::at(R) ─► Shape(R)   │ ─► Shape (public)     
                   └──────────────────────────────────────────────────────────────────────────┘
                                                                    │
Request ─► ResolveRevision (resolvers chain) ─► RevisionScope ──────┤ current()
                                                                    ▼
Response: subject ─► Projector(Shape(R)) ─► sources ─► conversions ↓ ─► array<key, value> ─► consumer
Request : input   ─► rules(Shape(R)) ─► validator ─► Upgrader(R → head): was ↑, upgrade(), defaults ─► validated()
Tooling : Shape(R) ─► SchemaVisitor (JSON Schema, lock, changelog) ─► Checker (diagnostics)
```

1. **Compilation.** The first time a class is needed at revision `R`, the `Compiler` runs its declaring hook on a
   `Probe` (no real subject, no request data): the descriptors are lazy, so no source runs. The `ShapeSelector`, one
   pure function, turns the head descriptors into the `Shape` of `R`. Shapes are memoized per `(class, revision)` and
   can be written to a file (`revision:cache`).
2. **Resolution.** The `ResolveRevision` middleware runs the resolvers chain and stores the revision in the
   `RevisionScope` (`scoped` binding). `Revision::current()` reads it.
3. **Projection (down).** The `Projector` pairs the compiled `Shape` with the fresh descriptors of the instance,
   evaluates only the visible sources, runs the `down` conversion chains, and returns `key → value`.
4. **Upgrade (up).** The rules of `Shape(R)` validate the input; the `Upgrader` walks the steps from `R` to the head
   (renames + `up` conversions, the `upgrade()` hook, defaults).
5. **Tooling.** Visitors export a `Shape` (JSON Schema, lock snapshot); the `Checker` runs its rules and returns
   diagnostics.

## 2. Layers and dependency rule

```
Support ← Configuration ← Revisions ← Definition ← Conversion ← Schema ← Compilation ← Projection ← Upgrade ← Export ← Checking ← Laravel ← Testing
                                            (Exceptions: usable by every layer)
```

| Layer | Folder | Content |
| --- | --- | --- |
| Support | `src/Support/` | Building blocks without internal dependency: `Undefined`, `Memo`, `Invoke`, `Floats`, `Json`, `Diagnostics`, `Diagnostic`, `Severity` |
| Configuration | `src/Configuration/` | `Configuration`: typed, validated, readonly options of the core (date formats, `missingDefault`, `strictValues`) |
| Revisions | `src/Revisions/` | `Revision` contract, `IsRevision` trait, `#[Meta]`, `RevisionOrder`, `RevisionScope` (ambient context) |
| Definition | `src/Definition/` | Public immutable type-level model: `TypeDefinition`, `Kind`, `Lifetime`, `Direction` |
| Conversion | `src/Conversion/` | `Converter` contract, `Converters` registry, built-in converters, `Cast`, `ConversionChain` |
| Schema | `src/Schema/` | `Schema` factory, `Descriptor` and its typed subclasses, `Source`, `InvalidValue`, `Change` (one `was()`), `History`, `TypeRegistry`, `DescribesSchema`, `DeclaresConversions`, `Presence`, `DeclarationKind`, `Groups` (`@internal`), `Revisioned` |
| Compilation | `src/Compilation/` | `Probe`, `Compiler`, `ShapeSelector` (the central pure function), its result `Shape`, `FieldDefinition`, `FieldVersion`, `Collision` (public, closure-free, `model.md` D45), `Shapes` (memo repository), `CompiledShapes` (file cache) |
| Projection | `src/Projection/` | `Projector`, `SourceReader`, `ValueErrorHandler` (`StrictValues`, `LenientValues`) |
| Upgrade | `src/Upgrade/` | `Upgrader`, `UpgradeStep`, `Input` (present / absent / null), `UpgradesInput` hook contract |
| Export | `src/Export/` | `SchemaVisitor` contract, `JsonSchemaExporter`, `Components` (`$ref`), `Changelog` |
| Checking | `src/Checking/` | `Check` contract, one class per rule, `Checker`, `Lock`, `ClassFinder` contract |
| Laravel | `src/Laravel/` | Service provider, configuration loading, middleware, resolvers, route macro, rule generation, `RevisionedRequest` / `RevisionedResource` traits, commands, queue capture |
| Testing | `src/Testing/` | Assertions and `TestResponse` helpers for application test suites |
| PHPStan (tooling, outside the layers) | `src/PHPStan/` + `extension.neon` | The PHPStan extension of the custom types (`CustomTypesExtension`, `CustomTypeMethod`); loaded only by PHPStan, never at run time (phpat: no layer depends on it nor on `PHPStan\*`); depends on `Schema` and PHPStan only; throws PHPStan's `ShouldNotHappenException`, outside the package's exception hierarchy |
| Exceptions | `src/Exceptions/` | Single exception hierarchy |

- A layer depends only on the layers to its left. Checked by `phpat` (`tests/Architecture/ArchitectureTest.php`).
- **Pure core.** Every layer left of `Laravel` is pure PHP: **no `Illuminate\*` at all**, not even contracts. Rules
  given to `->rules(...)` are opaque values to the core; only `Laravel` interprets them. Checked by `phpat`.
- Only `src/Laravel/` uses facades, global helpers (`config()`, `app()`, `request()`…) and the container. Checked by
  `spaze/phpstan-disallowed-calls`.

## 3. Revisions

`Revision` is the contract an application's enum implements (`extends \BackedEnum`); `IsRevision` gives it its
behaviour by delegating to `RevisionOrder`. Patterns: **Strategy** (the order is the enum's `order()`), **Ambient
Context** (`RevisionScope`).

- `RevisionOrder` is a pure service: index of a case, `compare()`, `previous()`, `next()`, `latest()`, `oldest()`,
  `between(from, to)` (inclusive, empty when `from` is newer). The order is checked on first use (`DefinitionException::invalidOrder()`) and memoized per enum
  class (`Support\Memo`); cases are keyed by **name**, never by backed value (int- and string-backed enums alike).
  Mixing two enums throws `DefinitionException::mixedRevisions()`.
- Typing: PHPStan reads `static` on an enum as the case itself (`ApiRevision::Atlas`), and a template bound by a case
  argument is that case. So `RevisionOrder::previous()`, `next()`, `between()` return `Revision`, and `IsRevision`
  narrows to `self` in its PHPDoc. `tests/Types/RevisionTypes.php` asserts what applications see.
- `#[Meta]` is read by `MetaReader::of()` (reflection, memoized per case); `Meta` checks its `Y-m-d` days in its
  constructor and the reader rethrows `DefinitionException::invalidMeta()` naming the case. Informative only (D21).
- `RevisionScope` is the **only** holder of the current revision: a stack for `using()` (Execute Around, restored in
  `finally`) above a base revision. The core reaches it through `RevisionScope::provider()`, a closure set **at
  boot** (configuration, not state; replaceable, `null` restores the default); the default provider is a process-local scope for non-Laravel use; the Laravel
  layer provides the `scoped` container binding (reset per request under Octane). API: `RevisionScope::get()`,
  `setBase(?Revision)` (the middleware), `current(class)` (head of `class` when nothing is set; the Laravel provider sets the configured
  fixed default outside a request, D31; a revision of
  another enum throws `foreignRevision()`), `using(revision, callback)`; `provider(null)` restores a new process-local scope
  (tests: `tests/TestCase` and `tests/FeatureTestCase` reset it after every test).

## 4. The definition model

`src/Definition/` holds the **type-level** model: what a field's type, kind and lifetime are, independently of any
subject and any request. With `Compilation\Shape` (§7), it is the **public contract** read by the export, the check,
the lock and external tools (`laravel-json-api`, `laravel-openapi-generator`). Patterns: **Immutable Value Object**,
**Named Constructor**.

- `Shape` and `FieldDefinition` are the same kind of public model, but live in `Compilation` (§7): a field carries its
  conversion chain, and `Conversion` sits right of `Definition` (`model.md` D45).
- `TypeDefinition`: a `Kind` (`String`, `Int`, `Float`, `Bool`, `Date`, `DateTime`, `Enum`, `List`, `Object`,
  `Custom`, `Any`), its constraints (`min`, `max`, `format`, `pattern`, `enum` class with its `backing` kind and
  `values`, `items`, `children`; `format` is a PHP date format for dates), its description, examples, deprecation.
- `Lifetime`: `since`, `until`; `covers(Revision): bool`.
- `final readonly`, private constructor, named constructors, `with*()` replacing setters, `add*()` appenders.

## 5. Schema descriptors

The user vocabulary. Patterns: **Static Factory** (`Schema::…`), **Immutable Fluent Builder** (every modifier returns
a clone), **Composite** (`ObjectSchema`, `ListSchema`), **Reference** (`ResourceSchema`), **Registry + Macro** (custom
types).

- `Schema` is a `final` class of static factories; its `__callStatic()` serves the macros of the `TypeRegistry`
  (`Schema::money()`).
- `Descriptor` is the abstract root: source, modifiers, conditions, rules, `History` (lifetime + ordered `Change`s).
  Every concrete descriptor is `final`, except the documented extension base `Descriptor` itself.
- `Source` is a value object: `Source::key('title')` (dotted paths allowed), `Source::closure($fn)`,
  `Source::declaredKey()`. **Every closure the library calls has one signature, `($value, $subject)`** (`model.md`
  §3): sources, conditions, `down:` / `up:` converters. One invoker (`Support\Invoke::value()`) calls them all. Their
  PHPDoc type is a plain `Closure`: `Closure(mixed, mixed)` would refuse `fn (int $cents, Order $order)`. A `Change` (`was()`) never holds a source (`model.md` §6.4).
- Configured defaults reach a descriptor through the final `configured(Configuration): static`, called by the
  compiler on every head descriptor: it applies the overridable `configure()` (a date without `format()` takes the
  configured format, `model.md` D29) and configures the schemas of its `was()` (`History::configured()`). A composite
  or custom type configures the descriptors it holds with their `configured()`. A descriptor never reads
  configuration itself.
- A descriptor builds its `TypeDefinition` (`toType()`); it never builds a `Shape` (that is the compiler's job) and
  never reads a revision other than those declared in its `History`.
- `ResourceSchema` (`Schema::resource()`) **references** another `Revisioned` class: its type is an `Object` kind
  whose `TypeDefinition::$resource` names the class (no children). The nested shape is the class's shape of the same
  declaration kind at the same revision (`model.md` D49), resolved by whoever reads the field
  (compiled for `($type->resource, $shape->kind, $field->revision)`, through `Shapes::for()` from US 5.2), never inlined: cycles compile.
- Composites (`ListSchema`, `ObjectSchema`) normalize only their own value: a list to `list` of elements, an object
  to the subject of its children. Projecting each item and child is the projection's job (a descriptor never reads
  the subject), before the composite's own `when()` and conversions (`model.md` D34). `TValue` is covariant (`Descriptor<string>` is a `Descriptor<mixed>`),
  so `serialize()` takes a `mixed` and passes any other type through.
- A descriptor owns the **value rules of its type**: `normalize(mixed): mixed` (subject data → PHP value of the type,
  or an `InvalidValue`, returned, never thrown: not named `ValueError`, which PHP already defines and `BackedEnum::from()`
  throws) and `serialize(mixed): mixed` (PHP value → JSON-ready value), `model.md` §6.3. It never reads the
  subject itself: that is the `SourceReader`'s job.
- `DescribesSchema` (implemented by a rule object) and `Revisioned` (implemented by a declaring class) are the two
  contracts of this layer an application implements. `Revisioned` is static and per `DeclarationKind` (`Response`,
  `RequestBody`, `RequestQuery`; `direction()`: down for a response, up for a request): `declarationKinds()` and the
  `@internal` bridge `describeRevisioned($kind, $probe)`, in which the class builds itself on the probe (`mixed`: the
  `Probe` lives in `Compilation`); the Laravel traits implement it (`model.md` D48). `Descriptor::toType()` merges the keywords of every declared
  rule implementing `DescribesSchema` into `TypeDefinition::$jsonSchema`, after those of `type()` (a later one wins),
  and refuses keywords that are not JSON-ready (Definitions stay serializable); `model.md` D36.
- `Descriptor::inGroup()` is a public `@internal` entry point: it sets the lifetime and default a group resolved
  without counting as a second call (`model.md` §4.4 rule 5). Only `Schema\Groups` calls it (disallowed calls).
- Conditions are **declared, never run** by the Schema layer: `when()` keeps the closure, `whenHas()` a `Presence`
  (a value: `null` for the field's own key, or a path). The projection evaluates presences before the source and
  conditions after normalization (`model.md` §6.4); the Laravel layer adds `whenLoaded()` the same way.

## 6. Conversions

Patterns: **Strategy** (`Converter`), **Registry** (`Converters`, keyed by `(from, to, Direction)`, a side being a `Kind` or a custom type's name),
**Pipeline** (`ConversionChain`).

- `Converter::convert(mixed $value, mixed $subject = null): mixed`, `lossless(): bool`, `extraRules(): list<mixed>` (e.g. `numeric`). A
  converter receives a normalized value of its source type; any other value passes through unchanged.
- `Converters` is an immutable instance (no static state): `register(Kind|string $from, Kind|string $to, Direction, $factory)`
  stores a factory `(TypeDefinition $from, TypeDefinition $to): ?Converter`, so a converter is built for the two
  types (`BackedToEnum` needs the enum class) or declined for them (`enum → int` of a string-backed enum, D41);
  `find(TypeDefinition, TypeDefinition, Direction)` resolves it; `keyOf()` (`int>float:down`, a custom type by its name:
  `custom(money)>float:down`) is what a chain stores. A custom type converts through the static
  `Schema\DeclaresConversions::conversions(Converters): Converters` of its class; `TypeRegistry::converters()` folds
  those of every registered type onto the built-in registry (no static `Converters`: it is rebuilt from the static
  `TypeRegistry`); the compiler passes it to `ShapeSelector::at()`, which stays pure (built-in registry by default).
  `Kind::Custom` and an empty name are refused as sides (`DefinitionException::invalidConversionSide()`).
- Built-ins are one class each under `Conversion\BuiltIn\` (`IntToFloat`, `NumericStringToFloat`, `EnumToBacked`,
  `BackedToEnum`…), registered in `Converters::builtIn()` for both directions (D25: the same cast serves both).
  `BackedToEnum` is the one converter that validates instead of passing a foreign value through: it throws
  `ConversionException`. `Cast::round()` / `floor()` / `ceil()` are named constructors of explicit converters.
- A user closure (`down:` / `up:`) becomes a `ClosureConverter`, always considered explicit (so never `conversion-lossy`).
  `ExplicitConverter` marks the converters an author may give to `was()` (`ClosureConverter`, the `Rounding` of
  `Cast`); `Change` stores them, never a bare closure. Only a `ClosureConverter` reads the subject (the one closure
  signature); built-ins and casts ignore it. A float → int conversion out of the int range throws (`Floats::toInt()`,
  `model.md` D44).
- Plans hold **one direction**, the one of the declaration kind (`model.md` D27): `down` for responses, `up` for
  requests.
- `ConversionChain` is planned step by step (`withStep($from, $to, $change, $explicit, $converters)`, in apply order)
  by the `ShapeSelector`, which alone decides the types of consecutive shapes. A step is `StepKind::Explicit` (the
  `was()` declares the direction: always a step), `Registered` (a registry key) or `Missing` (recorded, thrown by
  `apply()` only). Two types holding the same values (kind, enum class, custom name, items and children by key) add no
  step: constraints, formats and documentation never convert a value; a custom type compares its fragment too (spike
  F7). `bind(Converters, $explicit)`
  re-finds the registered converters by their types and takes the explicit ones by `was` index (an array, not a
  `History`: `Conversion` sits left of `Schema`). Every step keeps its `was` index. `apply()` runs the whole chain
  (down side); the upgrader runs an up chain step by step (`stepOf()`), normalizing the value to each shape and passing
  each step its snapshot (spike F3, `model.md` §7.1). A step serializes without its converter.
- `ConversionChain` is built once per `(field, revision, direction)` at compilation and only **references**
  converters, so it serializes; the closures are re-attached from the fresh descriptors at run time.
- A conversion failure (`BackedEnum::from()` on an unknown value) throws `ConversionException`, turned into a
  validation error by the Laravel layer.

## 7. Compilation: the probe

Patterns: **Null Object** (`Probe` stands for the subject), **Repository** (`Shapes`), **Memoization**.

- `Probe` stands for the subject: any access (property, method, offset, `isset`, count, iteration, string cast)
  throws `DefinitionException::subjectReadAtCompilation()` naming the class and the access.
- `Compiler` (`final readonly`, built with the `Configuration` and optional `Converters`, `TypeRegistry::converters()`
  by default) `compile(class-string, DeclarationKind, Revision): Shape` checks the class is `Revisioned`
  (`notRevisioned()`) and declares the kind (`undeclaredKind()`), calls `describeRevisioned($kind, new Probe($class))`,
  checks every entry is a descriptor under a string key (`invalidDeclaration()`), configures it (`configured()`), and
  calls
  `ShapeSelector::at($descriptors, $revision, $direction, $converters, $configuration)` (the direction of the class's
  declaration kind, the application's converters, the configuration whose `missingDefault` the selector resolves); the
  `Shape` then names its class and kind (`Shape::of()`). Last, every resource nested in a field's type (items and
  children included) must be `Revisioned` and declare the same kind (`model.md` D49): checked on the reference, the
  nested class is never compiled there.
- `ShapeSelector` is **the** pure function of the model (`specs/model.md` §4.5). Nothing else decides visibility,
  names or conversion plans. Every story touching shapes tests it, and an **invariant test** compares, for every
  fixture class and every revision, the projected keys with `Shape::names()`.
  `ShapeSelector::at($head, $revision, $direction, $converters, $configuration)` plans the direction of the
  declaration kind only (`model.md` D27) and returns a `Shape`, public, immutable, closure-free and serializable:
  - `Shape`: `revision`, `direction`, `fields` (visible, by **head key**, head order), `added` (up only: the fields
    added after the revision, each defined at its `since`, where its default is written, D23), `collisions` (a name
    written by two visible fields, recorded, never thrown); `names()`, `field()`.
  - `FieldDefinition`: `headKey`, `revision`, `name` and `type` at the revision (a composite's items and visible
    children resolved there, the children under their name there), `nullable` (from the descriptor in force: the
    head or a `was()` schema), `required`, `sometimes`, `conditional` (`Descriptor::isConditional()`), `default` (an `Undefined` without one;
    `null` for a `required()->since()` field without one when `missingDefault` is `Null`, `model.md` D47),
    `lifetime` (from the head, D46), `schemaChange` (the `was()` whose schema is in force, the reference to its fresh
    descriptor: rules, closures), `versions` (a `FieldVersion` — revision, `was` index, name, type — per applicable
    `was()`, newest first: what the upgrader renames from), the unbound `ConversionChain`, `items` (a list: a
    `FieldDefinition` under the key `*`) and `children` (an object: a nested `Shape`, with its own `added` and
    `collisions`).
  - Each `was()` at or after the revision applies, newest first, as a delta from the next newer shape. A chain step
    crosses the type of the newer shape at `X->next()` and of the older one at `X`; a composite's own chain only
    covers its own `was()`, and a `was()` without `schema:` converts only through an explicit converter: its children
    and items carry theirs. Two children under one name keep the first in the object's type; the nested `Shape`
    records the collision.
- `Shapes::for($class, $kind, $revision)` memoizes (one shape per declaration kind, `model.md` D48); `CompiledShapes` reads/writes the file of `revision:cache`
  (`bootstrap/cache/revisions.php`, `var_export`, OPcache friendly). The Laravel layer registers `revision:cache` /
  `revision:clear` with `ServiceProvider::optimizes()`, so `artisan optimize` / `optimize:clear` handle it. A stale
  file is the application's responsibility, as for `route:cache`.
- Declaration errors that are programming errors (mixed enums, `since` newer than `until` on one descriptor) throw
  `DefinitionException` at declaration time; everything else becomes a diagnostic.

## 8. Projection (response, down)

Patterns: **Interpreter** (the `Shape` is the program, the descriptors give the closures), **Adapter** at the edge
(the consumer places `key → value` where it wants).

- `Projector::project(Shape $shape, array $descriptors, mixed $subject): array<string, mixed>`.
- Per field, a fixed **Pipeline** (`model.md` §6.5): presence conditions → `SourceReader::read($source, $subject)`
  → `Descriptor::normalize()` → `when()` conditions → `ConversionChain::apply()` (down, with the subject) →
  `Descriptor::serialize()`.
  Each step has one owner; no step is skipped or reordered for a type.
- `SourceReader` reads keys and dotted paths on arrays, `ArrayAccess` and objects (public property, then `__get()`);
  a missing key reads `null`; closures are called with `($value, $subject)`, `$value` being the declared key read
  **without side effect** (`SourceReader::peek()`; the Laravel layer decorates it for Eloquent: attributes present,
  relations loaded).
- **Nesting** changes the subject, never the pipeline: an object with a source projects its children on the source's
  value (without one, on the parent's subject: `Source::parent()`), a list on each item, `ResourceSchema` delegates
  to the `Shape` of the other class at the same revision. For a composite, the children are projected **between**
  its `normalize()` (which only checks the container) and its own `when()` / down conversions, which therefore
  receive the projected children; its `serialize()` receives them serialized (`model.md` D34).
- **Value errors** go to a `ValueErrorHandler` (**Strategy**): `StrictValues` throws `ValueException`;
  `LenientValues` casts and hands the error to a reporter. Chosen from `Configuration::strictValues`.
- A field whose condition fails is omitted. No `MissingValue` in the core.
- The projector never mutates the subject; projecting twice gives the same result.

## 9. Upgrade (request, up)

Patterns: **Pipeline** (one `UpgradeStep` per revision transition), **Template Method** hook (`upgrade()`),
**Special Case** (`Input` tells absent from `null`).

- `Upgrader::upgrade(Shape $from, array $input, UpgradesInput $hook): array` runs, for each step `X → X->next()`:
  renames + `up` conversions of the `was(X)`, then `$hook->upgrade(X->next(), $input)`, then the defaults of the fields
  `since(X->next())`.
- Conversions and defaults apply to **present** keys only; a default never replaces an explicit `null`.
- The upgrader is pure: it does not validate. The Laravel layer validates at `R` first, then upgrades.

## 10. Laravel bridge

- `Laravel\RevisionsServiceProvider`: configuration, `RevisionScope` provider, `scoped` binding, commands, route
  macro, resolvers, middleware alias.
- `Laravel\Http\Resolvers\*` — **Chain of Responsibility** of `RevisionResolver`s built from the configuration.
- `Laravel\Http\ResolveRevision` — middleware (400 on unknown value, response headers, `Vary`, Deprecation / Sunset).
- `Laravel\Validation\RuleCompiler` — **Visitor** over `TypeDefinition`s; one `RuleMapper` per `Kind` (registry),
  producing Laravel rules under the names of the revision.
- `Laravel\Requests\RevisionedRequest` and `Laravel\Resources\RevisionedResource` — traits **declared for
  applications**, used by no class of the package.
- `Laravel\Console\*` — `revision:check`, `revision:lock`, `revision:cache`, `revision:clear`, `revision:export`,
  `revision:changelog`, `revision:prune`.
- `Laravel\Queue\CapturesRevision` — job middleware / dispatch hook capturing the revision at dispatch.

## 11. Export

Patterns: **Visitor** (`SchemaVisitor` over `Shape` / `TypeDefinition`), **Registry** (`Components` for `$ref`).

- `JsonSchemaExporter` (draft 2020-12) is the reference visitor; the lock snapshot and the OpenAPI generator read its
  output. Other formats are new visitors, without touching the core.
- `Changelog` diffs two consecutive `Shape`s of each class (added, removed, renamed — known from the `History`, never
  guessed —, type changed) and renders Markdown with the revisions' `meta()`.

## 12. Checking and lock

Patterns: **Specification** (one `Check` class per rule), **Notification** (`Diagnostics` collects, never throws),
**Snapshot** (`Lock`).

- `Check::run(CheckContext): void` adds diagnostics; `Checker` runs the registered checks on every discovered class
  and the enum.
- `Diagnostic`: `code` (kebab-case, a constant of its check), `severity`, `class`, `field`, `revision`, `message`.
- `ClassFinder` is a contract; the Laravel layer implements it over `discovery.paths` (classes implementing
  `Revisioned`) plus `discovery.classes`.
- `Lock` writes and compares normalized snapshots (sorted keys) and the recorded `order()`.

## 13. Extension points

| Extension point | Contract | Registered in |
| --- | --- | --- |
| Custom type | `extends Schema\Descriptor` (+ its converters) | `TypeRegistry` (macro on `Schema`) |
| Conversion | `Conversion\Converter` | `Converters` |
| Export format | `Export\SchemaVisitor` | used directly |
| Check rule | `Checking\Check` | `Checker` |
| Resolver | `Laravel\Http\RevisionResolver` | `config/revisions.php` |
| Rule mapping | `Laravel\Validation\RuleMapper` | `RuleCompiler` |
| Consumer | reads `Shape` + calls `Projector` / `Upgrader` | — |
| Value errors | `Projection\ValueErrorHandler` | `Configuration` (Laravel: reporter = `report()`) |

## 14. Errors

`Exceptions\RevisionsException` (marker interface) ← `DefinitionException` (`LogicException`: programming errors in
declarations), `ConversionException`, `ValueException` (a subject value the type refuses, strict mode),
`UnknownRevisionException` (unknown client value), `LockException`. Named
constructors only (`DefinitionException::mixedRevisions()`); messages name the class, the field and the revision.

`Exceptions` depends only on `Support` (phpat, PHPDoc included): an exception takes `\BackedEnum`, `\UnitEnum` or
`string`, never `Revision` (`DefinitionException::mixedRevisions(\BackedEnum $a, \BackedEnum $b)`). Exceptions are
pure PHP: the HTTP rendering (the 400 of `UnknownRevisionException`, validation errors) lives in the Laravel layer
(exception handler / renderable), never in the exception class.

## 15. Supported versions

PHP `^8.3`, Laravel `^12.46 || ^13.0` (Laravel 14 as soon as it is released). Only APIs present in every supported
Laravel version are used. CI matrix: PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13, lowest and highest dependencies.

## 16. Code conventions

| Topic | Rule |
| --- | --- |
| Classes | `final` by default. Extensible base classes, listed in the architecture test: `Schema\Descriptor` |
| Traits | None in `src/`, except those declared for applications: `Revisions\IsRevision`, `Laravel\Requests\RevisionedRequest`, `Laravel\Resources\RevisionedResource`, `Testing\InteractsWithRevisions` (used by no class of the package; list checked by `DeclaredTraitsTest`) |
| Global state | No singleton, no mutable static property. Allowed: `Support\Memo` (pure memos of what depends only on the code, flushable), `RevisionScope::provider()` (set at boot; replaceable, `null` restores the default), `Schema\TypeRegistry` (custom types registered at boot, `flush()` for tests, `model.md` D42) |
| Facades and helpers | Only in `src/Laravel/` |
| Core purity | No `Illuminate\*` left of `Laravel` |
| Types | `declare(strict_types=1)`, every signature typed, PHPStan max; `mixed` only for free values, documented |
| PHPDoc | Generics on the public API (`Descriptor<TValue>`, `Closure(TValue): mixed`); no redundant PHPDoc |
| Naming | Interfaces without suffix (`Converter`, `Check`); implementations named after what they handle (`IntToFloat`, `RequiredSinceWithoutDefault`) |
| Public API | Everything not documented as public is `@internal` and may change in a minor version |
| Language | Everything is in English, `docs/` included |

## 17. Tests

- **Suites:** `tests/Unit` (one test class per class), `tests/Feature` (Orchestra Testbench scenarios on the fixture
  application of `tests/Fixtures/`), `tests/Architecture` (phpat, run by PHPStan), `tests/Types` (generics asserted
  with `assertType()`, analysed, never run), `tests/Benchmark`.
- **Reference scenario:** the `ApiRevision` enum (`Genesis`, `Winter25`, `Spring26`, `Atlas`), `OrderResource` and
  `StoreOrderRequest` of `specs/model.md` live in `tests/Fixtures/` and are used by every Feature test.
- **Invariant test:** for every fixture class and every revision, projection keys = `Shape` names, and every projected
  document validates against the exported JSON Schema of its revision.
- **Expected files** (`tests/Feature/<Scenario>/expected/*.json`) are rewritten by `composer fixtures:update` and
  reviewed in the diff.
- `tests/Unit/Architecture/` holds the architecture checks phpat cannot do (the declared traits); they test the
  code base, not one class.
- Arrange / Act / Assert; test names are sentences (`test_a_renamed_field_keeps_its_old_name_up_to_its_revision`).
- **Workflow:** contract (signatures and PHPDoc, neutral bodies) → red tests → implementation. Never implement
  behaviour that has no test yet.

## 18. Recipes

### Add a built-in type

1. `src/Schema/<Type>Schema.php` (`final`, extends `Descriptor`), its `toType()`, its modifiers.
2. Its `Kind` (if new), its converters in `Converters::builtIn()`, its `RuleMapper`, its JSON Schema visit.
3. Unit tests: the type definition, each converter in each direction, the rules, the export.

### Add a conversion

1. `src/Conversion/BuiltIn/<From>To<To>.php` implementing `Converter`; `lossless()` honest for every value it accepts.
2. Register a factory for it in `Converters::builtIn()` (both directions unless the model says otherwise; the factory
   may decline a pair of types); update `specs/model.md` §5.
3. Unit tests: values at the edges, the lossy cases reported by `conversion-lossy`.

### Add a check rule

1. `src/Checking/Checks/<Rule>.php` implementing `Check`, its `CODE` constant; register it in `Checker`.
2. Add the code to `specs/model.md` §9.
3. Unit test: one passing and one failing declaration; the diagnostic names class, field, revision.

## 19. Automatic safeguards

| Safeguard | What it checks |
| --- | --- |
| PHPStan max + Larastan + strict rules + phpstan-phpunit | Types, dead code, comparisons, Laravel API |
| `phpat` (`tests/Architecture/ArchitectureTest.php`) | Dependency rule, `Exceptions` on `Support` only, pure core (no `Illuminate` left of `Laravel`), `final` classes, abstract only for base classes, no package class using a trait, interface names |
| `tests/Unit/Architecture/DeclaredTraitsTest.php` | Only the listed traits are declared in `src/` (phpat cannot see a trait declaration) |
| `spaze/phpstan-disallowed-calls` | Facades, global helpers, `Illuminate\Support` helpers (`collect()`, `data_get()`, `value()`…) and `Container::getInstance()` outside `Laravel/` (phpat does not see functions); debug output everywhere |
| PHP-CS-Fixer (PER-CS) | Style, `declare(strict_types=1)` |
| JSON Schema validation of projected documents | Every Feature output matches the export of its revision |
| Coverage ≥ 90 % (`bin/coverage-check.php`) | Tested lines |
| Infection (MSI ≥ 80 %) | Test quality; a change generating no mutant is not measured (`ignoreMsiWithNoMutations`) |
| Benchmark gate | Projection time and memory at the head and the oldest revision |
