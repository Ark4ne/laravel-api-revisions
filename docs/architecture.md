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
Declaring class ─► │ Probe ─► toArray() / body() / queryParameters() ─► descriptors          │
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
   pure function, turns the head descriptors into the `Shape` of `R`. Shapes are memoized per
   `(class, kind, revision)` by the `Shapes` repository (one per application) and can be written to a file
   (`revision:cache`).
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
| Schema | `src/Schema/` | `Schema` factory, `Descriptor` and its typed subclasses, `Source`, `InvalidValue`, `Change` (one `was()`), `History`, `TypeRegistry`, `DescribesSchema`, `DeclaresConversions`, `Presence`, `DeclarationKind`, `Groups` (`@internal`), `Revisioned`, `EagerSchema` (a plain value wrapped by a bridge) |
| Compilation | `src/Compilation/` | `Probe`, `Compiler`, `ShapeSelector` (the central pure function), its result `Shape`, `FieldDefinition`, `FieldVersion`, `Collision` (public, closure-free, `model.md` D45), `Shapes` (memo repository), `CompiledShapes` (file cache) |
| Projection | `src/Projection/` | `Projector`, `SourceReader` (`PlainSourceReader`), `ValueErrorHandler` (`StrictValues`, `LenientValues`) |
| Upgrade | `src/Upgrade/` | `Upgrader`, `UpgradesInput` hook contract, `UpgradeRun` (`@internal`: what one upgrade carries down its levels) |
| Export | `src/Export/` | `SchemaVisitor` contract, `JsonSchemaExporter`, `Components` (`$ref`), `Changelog` |
| Checking | `src/Checking/` | `Check` contract, one class per rule, `Checker`, `Lock`, `ClassFinder` contract |
| Laravel | `src/Laravel/` | Service provider, configuration loading, middleware, resolvers, route macro, rule generation, `RevisionedRequest` / `RevisionedResource` traits (`Requests\RequestHook`, `Resources\EloquentSourceReader`, `Resources\DescribeSubject`), commands, queue capture |
| Testing | `src/Testing/` | Assertions and `TestResponse` helpers for application test suites |
| PHPStan (tooling, outside the layers) | `src/PHPStan/` + `extension.neon` | The PHPStan extensions: the custom types (`CustomTypesExtension`) and the route macro (`RevisionedRouteExtension`, reading the signature of `Laravel\Routing\RevisionedRoutes::group()`), their methods built as `DeclaredMethod`; loaded only by PHPStan, never at run time (phpat: no layer depends on it nor on `PHPStan\*`); depends on `Schema`, `RevisionedRoutes` and PHPStan only; throws PHPStan's `ShouldNotHappenException`, outside the package's exception hierarchy |
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
  The only descriptor carrying a captured value is `EagerSchema` (a closure source returning it, nullable `any`, always
  conditional), built only by the bridge of a declaring class for a plain value (`RevisionedResource`, `model.md` D61).
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
  (compiled for `($type->resource, $shape->kind, $field->revision)`, through `Shapes::for()`), never inlined: cycles compile.
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
  `@internal` bridge `describeRevisioned($kind, $subject)`, in which the class builds itself on its subject: the probe
  at compilation (`mixed`: the `Probe` lives in `Compilation`), the value of a nested resource at projection; called by
  the compiler, the projector and the upgrader only; the Laravel traits implement it (`model.md` D48, D50). `Descriptor::toType()` merges the keywords of every declared
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
- `Converters` is an immutable instance (no static state of its own): `register(Kind|string $from, Kind|string $to, Direction, $factory)`
  stores a factory `(TypeDefinition $from, TypeDefinition $to): ?Converter`, so a converter is built for the two
  types (`BackedToEnum` needs the enum class) or declined for them (`enum → int` of a string-backed enum, D41);
  `find(TypeDefinition, TypeDefinition, Direction)` resolves it; `keyOf()` (`int>float:down`, a custom type by its name:
  `custom(money)>float:down`) is what a chain stores. A custom type converts through the static
  `Schema\DeclaresConversions::conversions(Converters): Converters` of its class; `TypeRegistry::converters()` folds
  those of every registered type onto the built-in registry; without argument the result is kept in the
  `TypeRegistry` until the next `register()` or `flush()` (a projection asks for it on every render, US 5.5); the
  compiler passes it to `ShapeSelector::at()`, which stays pure (built-in registry by default).
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
    `collisions`): those of the **head** composite at `headAt` (the revision, or the one after its newest
    `was(schema:)`), whatever schema a `was()` puts in force (that one is the `type`, for rules and export; `model.md`
    D52). Rules and export read the `type`, never `children` / `items`.
  - Each `was()` at or after the revision applies, newest first, as a delta from the next newer shape. A chain step
    crosses the type of the newer shape at `X->next()` and of the older one at `X`; a composite's own chain only
    covers its own `was()`, and a `was()` without `schema:` converts only through an explicit converter: its children
    and items carry theirs. Two children under one name keep the first in the object's type; the nested `Shape`
    records the collision.
- `Shapes` (`final`, built with its `Compiler`) `for($class, $kind, $revision)` compiles each `(class, kind, revision)`
  once (one shape per declaration kind, `model.md` D48) and keeps it in the instance, never in static state: two
  configurations never share a shape; the Laravel layer binds one instance (US 8.1), `flush()` forgets the shapes
  (tests, Octane). A compilation that throws stores nothing. Custom types are registered at boot, before
  any `for()`; a type registered later needs a `flush()`. `CompiledShapes` reads/writes the file of `revision:cache`
  (`bootstrap/cache/revisions.php`, `model.md` D56): a `var_export` of **serialized** shapes by `class@kind@revision`,
  an array of strings OPcache keeps in shared memory, each shape unserialized on its first `for()`, stamped with
  `CompiledShapes::FORMAT` (bump it whenever `Shape` or a class it holds changes: a file of another format is
  refused, never unserialized into a stale shape); written to a
  temporary file then renamed (a reader never sees half a file). `Shapes` reads it before compiling; `flush()` keeps
  it. The Laravel layer registers `revision:cache` / `revision:clear` with `ServiceProvider::optimizes()`, so
  `artisan optimize` / `optimize:clear` handle it. A stale file is the application's responsibility, as for
  `route:cache`.
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
- `Projector` (`final readonly`) is built with the `Configuration` the shapes were compiled with (the descriptor that
  serializes is `configure()`d with it, its own defaults only: serializing never reads the history, and a render
  cannot afford `configured()` per field, US 5.5; the head, or the schema of the `was()` in force, `FieldDefinition::$schemaChange`),
  the `Converters` the unbound chains are bound to (`TypeRegistry::converters()` by default; explicit steps take the
  `down:` of the fresh descriptors) and a `SourceReader`. Descriptors missing a field of the shape throw
  `DefinitionException::missingDescriptor()`. A `when()` passes only when it returns `true`.
- `SourceReader` (**Strategy**, an interface since US 8.5: `PlainSourceReader` in the core, decorated by the Laravel
  layer's `EloquentSourceReader`) reads keys and dotted paths on arrays, `ArrayAccess` and objects (public property, then `__get()`
  called directly, never after an `isset()`: the subject's own errors show, spike F11); a missing key reads `null`;
  `has()` answers `whenHas()` (a `Presence` without key checks the source path, else the declared key, D37),
  checking each segment before reading it, in the order of `read()` (array offset, public property, `__isset()`,
  `ArrayAccess`);
  closures are called with `($value, $subject)`, `$value` being the declared key read **without side effect**
  (`SourceReader::peek()`: an array offset or an initialized public property, never a magic getter nor an
  `ArrayAccess` call; the Laravel layer decorates it for Eloquent: attributes present, relations loaded).
  `loaded()` answers `whenLoaded()` (a `Presence` with `relation`): `has()` for a plain subject, `relationLoaded()`
  per segment on an Eloquent model (`model.md` D61).
- **Nesting** changes the subject, never the pipeline: an object with a source projects its children on the source's
  value (without one, on the parent's subject: `Source::parent()`), a list on each item (an item without source is
  the element itself; an item whose condition fails is dropped, `model.md` D51), `ResourceSchema` delegates to the
  `Shape` of the other class at the same revision (`Shapes::for($class, Response, $field->headAt)`, a `Shapes` given to
  the projector or one compiling with its configuration) and to its descriptors built on the value
  (`describeRevisioned()`, D50). For a composite, the head's children are projected **between** its `normalize()`
  (which only checks the container) and its own `when()` / down conversions, which therefore receive the projected,
  serialized children; its `serialize()` receives them too (`model.md` D34, D52). An item is told by a flag, never by
  its `*` key: a closure source gets the element as `$value`. Descriptors that do not match the shape (a composite on
  one side only) throw `missingDescriptor()`.
- **Value errors** go to a `ValueErrorHandler` (**Strategy**, `handle(ValueException $error, mixed $fallback): mixed`):
  `StrictValues` throws the `ValueException`; `LenientValues` hands it to its reporter (`Closure(ValueException): void`,
  optional) and returns the fallback: the cast of a refused value (rendered as it is: no `when()`, chain nor
  serializer), `null` for a failed conversion or a non-nullable `null`, resolved or given by a `down:` (`model.md`
  D53). The projector takes a handler, or builds one from
  `Configuration::$strictValues` (`true` in the core). A `ValueException` exposes `class`, `field`, `revision` (the
  Laravel reporter reports once per class and field) and never holds the value in its message; its stack trace holds
  call arguments (the subject of an item) unless `zend.exception_ignore_args` is on, as in production `php.ini`.
- A field whose condition fails is omitted. No `MissingValue` in the core.
- The projector never mutates the subject; projecting twice gives the same result.

## 9. Upgrade (request, up)

Patterns: **Pipeline** (one step per revision transition, read from the `Shape`), **Template Method** hook
(`upgrade()`). Absent and `null` are told apart by `array_key_exists()` on the input array, never by `isset()`.

- `Upgrader` (`final readonly`, built like the `Projector` with the `Configuration` and optional `Converters`):
  `upgrade(Shape $from, array $descriptors, array $input, ?UpgradesInput $hook = null): array`. Like the projector it
  takes the fresh head descriptors: they give the `up:` closures and the descriptor that normalizes and serializes.
  The steps are read from the up `Shape` of `R`, never recomputed: its visible fields and `added` fields (each compiled
  at its `since`), `FieldDefinition::$versions` (`FieldVersion`: the name, `was()` index and schema in force at each
  revision, `$schemaChange`), `FieldDefinition::nameAt()` and `ConversionChain::stepOf()` (the step crossing a
  `was()`). A down shape throws `DefinitionException::notAnUpShape()`. For each step `X → X->next()`:
  1. the values of the fields `until(X)` are set aside; every field existing at both revisions with a `was(X)`: its
     value read from a **snapshot** of the step under its name at `X`; when not `null` and the chain has a step there,
     normalized by the descriptor in force at `X` (`ValueException::refused()` on a refusal) and converted, the step
     receiving `($value, $snapshot)`; all old names removed, then the new ones written (spike F1); the retired values
     put back under the names no newer field took;
  2. `$hook->upgrade(X->next(), $input)`;
  3. the defaults of the `added` fields `since(X->next())`, under their name there, unless the key is present (even
     `null`) with a value that is not a retired one;
  4. the retired values still present are dropped (after the hook, which may consume them).
  Then the values converted or defaulted are serialized by their head descriptor (`configure()`d); the others stay as
  sent. The result is in the head order, keys the shape does not know last (`model.md` D54).
- **Nesting** (`model.md` D55): each step runs on every level in one pass (`Upgrader::level()` recursing through
  `value()`): an object's children (`FieldDefinition::$children`, compiled at `headAt`), each item of a list
  (`$items`), a nested resource (`Shapes::for($class, $kind of the request, $field->headAt)` and
  `describeRevisioned($kind, $value)`, a `Shapes` given to the upgrader or one compiling with its configuration). A
  composite whose chain converts at `X` is converted whole and not stepped into; before `headAt` its value waits for
  its conversion. Only the request's hook is called. The converted and defaulted values are tracked by their path of
  head keys (a list whole) and serialized at the end by their head descriptor; a composite converted or defaulted is
  serialized deep, child by child, never by its own `serialize()`. A composite or resource on one side only
  (descriptor vs shape) throws `missingDescriptor()`.
- Conversions and defaults apply to **present** keys only; a default never replaces an explicit `null`. A value the
  type in force refuses, or a conversion rejects (`ConversionException` but `missing()`, a declaration error), throws
  `InvalidInputException::at($key, $cause)`, `$key` the client's key: the names at the client's revision
  (`UpgradeRun::$client`, `FieldDefinition::nameAt()`), dotted through children, item indexes and nested resources
  (`model.md` D63; an index a hook reorders is no longer the client's).
- The upgrader is pure: it does not validate and never mutates its input. The Laravel layer validates at `R` first,
  then upgrades.

## 10. Laravel bridge

- `Laravel\RevisionsServiceProvider`: configuration, `RevisionScope` provider, `scoped` binding, commands, route
  macro, resolvers, middleware alias. Since US 8.1: `config/revisions.php` merged and publishable (tag
  `revisions-config`); `Laravel\RevisionsConfig::fromArray($config, $debug)` (`final readonly`) validates every key
  lazily, on first use (`ConfigurationException`), and builds the core `Configuration` (`strict_values: null` follows
  `app.debug`); singletons `RevisionsConfig`, `Configuration` and `Shapes` (compiled with it: shapes depend on the code
  only, so they survive Octane requests); `scoped` bindings `RevisionScope` (base: the configured fixed default, D31)
  and `ValueErrorHandler` (`StrictValues`, or `LenientValues` reporting to the `ExceptionHandler` once per class and
  field and request); `register()` points `RevisionScope::provider()` at the container of each call
  (`Container::getInstance()`: Octane runs each request in a clone of the application). Under Octane, add
  `RevisionsConfig::class` and `Shapes::class` to `octane.warm`: built lazily, a singleton first made during a request
  would live in that request's sandbox only.
- `Laravel\Http\Resolvers\*` — **Chain of Responsibility** of `RevisionResolver`s built from the configuration.
  Since US 8.2: `ResolverChain` (`scoped`, built by the provider: each `[class, options]` of `RevisionsConfig::$resolvers`
  made by the container with its options as named arguments, then the `default`), `HeaderResolver`, `QueryResolver`,
  `PathResolver` (built-in resolvers read one value through the `@internal` `RevisionValue::parse()`, `model.md`
  D57). An unknown value throws `UnknownRevisionException` and stops the chain.
- `Laravel\Http\ResolveRevision` — middleware (400 on unknown value, response headers, `Vary`, Deprecation / Sunset).
  Since US 8.3 (`model.md` D58): alias `revision`, registered in `boot()`; resolved by the container on each request
  (the scoped `ResolverChain` and `RevisionScope` injected); it answers the 400 itself from `UnknownRevisionException`
  (no exception renderer, nothing reported); the days of `#[Meta]` read in `app.timezone`.
  `Vary`: the headers of the resolvers implementing the optional `Laravel\Http\VariesOn` (D59). Since US 8.4 (D60):
  it forgets the route parameters of the chain's `PathResolver`s and sets them as URL defaults.
- `Laravel\Resources\RevisionedResource` (since US 8.5, `model.md` D61) — overrides `JsonResource::resolve()` only:
  projects the descriptors of `toArray()` with the container's `Projector` (`scoped`: `EloquentSourceReader`, the
  `Shapes`, the `ValueErrorHandler`) on the shape of `current()`, then walks the `filter()`ed `toArray()` to keep its
  order and its plain values / native conditions. Its bridge builds the resource on `DescribeSubject` (a permissive
  **Null Object**, `@internal`) with an empty `Request` at compilation (the shape never depends on the request served;
  a plain value failing there throws `DefinitionException::failedDescribing()`); at a nested render it adds a never-rendered field for each declared key
  missing (the declared keys memoized per class, `Support\Memo`). Its private helpers are prefixed `revisioned` (a
  trait shares the namespace of the application's class).
- `Laravel\Routing\RevisionedRoutes::group()` — the body of the route macro `revisioned`, registered on the `Router` in
  the provider's `boot()` (a macro closure is bound to the router, so it is not static); prefix, enum constraint and
  `revision` middleware.
- `Laravel\Validation\RuleCompiler` — **Visitor** over `TypeDefinition`s; one `RuleMapper` per `Kind` (registry),
  producing Laravel rules under the names of the revision. Since US 8.6 (`model.md` D62): `final readonly`, a
  singleton of the provider built on the application's `Shapes`; `compile(Shape, descriptors)` walks the visible
  fields of an up shape (the `added` ones never), takes presence and nullability from the `FieldDefinition`, the type
  rules from the mapper of `$field->type` (the type at the revision), the conversion rules from the first step of the
  chain (a `Registered` one only, bound against `TypeRegistry::converters()` or the registry given), the user rules from
  the descriptor in force (`schemaChange`); nests through `children` / `items` where the head is in force, through the
  `was()` schema and `$field->type` otherwise, and through `Shapes::for($class, kind, revision)` and
  `describeRevisioned($kind, Probe)` for a nested resource. Mappers in `Laravel\Validation\Mappers\` (`StringRules`,
  `NumberRules`, `DateRules`, `EnumRules`, `ArrayRules`, `FixedRules`), each `final readonly`; the default one per kind is an
  exhaustive `match`, a mapper given replaces it (keys checked against `Kind`). The walk carries a guard, the nearest
  parent the client may leave out (D62). The first conversion step is bound with `ConversionChain::bind()`, as the
  projector and the upgrader do.
- `Laravel\Requests\RevisionedRequest` and `Laravel\Resources\RevisionedResource` — traits **declared for
  applications**, used by no class of the package.
  Since US 8.7 (`model.md` D63): `RevisionedRequest` declares `[RequestBody, RequestQuery]`; `describeRevisioned()`
  builds `new static()` (no input needed) and returns `body()` or `queryParameters()` (`query()` is Laravel's).
  `rules()` compiles both shapes of `current()` with the container's `RuleCompiler`, refusing a name both declare
  (`DefinitionException::declaredTwice()`). `getValidatorInstance()` registers the upgrade as the **last** `after`
  hook of each validator (the application's hooks first; skipped when errors exist): the validated input is split by
  the names of the query shape, each part upgraded by the container's `Upgrader` (singleton), the body with the hook
  (`Requests\RequestHook`, `@internal`, adapts the `protected upgrade()` to `UpgradesInput`); an
  `InvalidInputException` becomes an error on its key (`addFailure($key, 'in')`, or its `reason` as the message).
  `validated()` / `safe()` read the stored head (running the validation first when it has not run).
- `Laravel\Console\*` — `revision:check`, `revision:lock`, `revision:cache`, `revision:clear`, `revision:export`,
  `revision:changelog`, `revision:prune`.
  Since US 5.3: `revision:cache` (`CacheCommand`: discovered classes × `declarationKinds()` × `order()`, compiled
  with a fresh `Compiler`, never the cached file; a failure leaves the previous file) and `revision:clear`
  (`ClearCommand`), registered in the console only.
- `Laravel\Discovery\PathClassFinder` — the concrete `Revisioned` classes declared in the PHP files under
  `discovery.paths` (read from their tokens, then autoloaded), plus `discovery.classes` (an entry that is not a
  concrete `Revisioned` class throws `ConfigurationException`); used by `revision:cache`; US 9.1 makes it implement the `ClassFinder` contract.
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
| Resolver | `Laravel\Http\RevisionResolver` (+ `VariesOn` when it reads headers) | `config/revisions.php` |
| Rule mapping | `Laravel\Validation\RuleMapper` | `RuleCompiler` |
| Consumer | reads `Shape` + calls `Projector` / `Upgrader` | — |
| Value errors | `Projection\ValueErrorHandler` | `Configuration` (Laravel: reporter = `report()`) |

## 14. Errors

`Exceptions\RevisionsException` (marker interface) ← `DefinitionException` (`LogicException`: programming errors in
declarations), `ConversionException`, `ValueException` (a subject value the type refuses, strict mode),
`ConfigurationException` (`InvalidArgumentException`: an invalid `config/revisions.php`), `CacheException`
(`RuntimeException`: the compiled shapes file unwritable or invalid),
`UnknownRevisionException` (`RuntimeException`: unknown client value), `InvalidInputException` (`RuntimeException`:
an input value the upgrade rejects, chaining its `ConversionException` or `ValueException`, D63), `LockException`. Named
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
| Global state | No singleton, no mutable static property. Allowed: `Support\Memo` (pure memos of what depends only on the code, flushable), `RevisionScope::provider()` (set at boot; replaceable, `null` restores the default), `Schema\TypeRegistry` (custom types registered at boot and the default `Converters` derived from them, rebuilt after `register()` / `flush()`; `flush()` for tests, `model.md` D42), the `revisioned` router macro (Laravel's `Macroable`, registered at boot, reading the router's container) |
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
- `tests/Unit/Projection/ProjectionInvariantTest.php` tests the model, not one class: every response declaration of
  `tests/Fixtures/Declarations/` (found by scanning it, deliberately broken ones listed as excluded) projected at every
  revision gives the shape's names, recursively.
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
| Benchmark gate (`bin/benchmark.php`, `composer benchmark`) | Projection time and memory of 1 000 orders at the head and the oldest revision (a `RevisionedResource` resolved per order in a bare container holding the services the provider binds, shapes compiled once; since US 8.5) as **ratios** to a plain `JsonResource`: scenarios interleaved in each run, median of the per-run time ratios, peak memory with the outputs held. Fails beyond `bin/benchmark/reference.json` by more than its tolerance (10 %), or on a reference scenario no longer measured; refuses to run under Xdebug. The reference is measured without Xdebug, as CI runs it; `--update` rewrites it (keeping the tolerance): review the diff |
