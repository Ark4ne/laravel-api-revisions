# Consumer epic — what `laravel-json-api` and `laravel-openapi-generator` need

Status: **decided** (2026-11-14), written from the `laravel-json-api` study; decisions in `model.md` D74–D78, stories
in `epics.md` E12 · Related: `epics.md` §4 (X1, X2),
`laravel-json-api/docs/rfc/api-revisions-integration.md`, `laravel-openapi-generator/docs/rfc/api-revisions.md`

## 1. Goal

This library stays independent and knows neither JSON:API nor OpenAPI. Two libraries build on it:

- `laravel-json-api` 3.0 **requires** it and drops its own revision model, its value descriptors and its value
  rendering: its hooks return this library's descriptors, and this library renders them. It keeps what is JSON:API
  (document, relationships, `include`, sparse fieldsets, loading, links).
- `laravel-openapi-generator` has an optional integration reading the JSON Schema export and the changelog.

A `laravel-json-api` resource declares itself as **one `Revisioned` response** whose top level is one object per hook
(`attributes`, `relationships`, `meta`, `documentMeta`). No new `DeclarationKind` is needed.

The directory is renamed `laravel-api-revisions`, as the package already is.

## 2. Requirements

| # | Requirement | Why | Size |
|---|---|---|---|
| **R1** | **`Revisioned` without the Laravel traits is a supported path.** A consumer implementing `Revisioned` itself (its own probe, ignoring the `Probe` given) is compiled, cached, checked, locked and exported like a class using `RevisionedResource`. Documented in `architecture.md` §13 with a fixture and an invariant test. | `laravel-json-api` runs its hooks on its own recording probe | S |
| **R2** | **Class lifetime.** A `Revisioned` class may not exist at every revision (`laravel-json-api`'s `#[JsonApiSince]` / `#[JsonApiUntil]`). A contract (an optional interface `HasLifetime::lifetime(): Lifetime`, or attributes read by the compiler) honoured by `Shapes`, `revision:cache`, the export, the lock, the changelog (resource added / removed) and the check (a `Schema::resource()` to a class absent from a revision is an error). | a JSON:API type added or retired | M |
| **R9** | **Projection plan and declaration built once.** The projection is compiled once per class, revision and set of fields into a plan (source, presences, conditions, bound chain and configured serializer resolved ahead), then run per subject; the declaration is built once, not per subject. Public entry point: build a plan from a `Shape`, descriptors and an optional subset of head keys; run it on a subject. `RevisionedResource` uses it (a template instance whose `resource` is set to each subject, so closures reading `$this->resource` keep working; a plain value in `toArray()` can no longer be captured per subject: decide its fate, CQ5). Spike: worktree `spike/projection-plan`, `bin/spike.php` — identical outputs at the four revisions, 25 µs → 3.7 µs per item (2.8 µs reading a property directly instead of `get_object_vars()` per key). Lower `bin/benchmark/reference.json` accordingly. | `laravel-json-api` 3.0 delegates the rendering of its values to this library and must not get slower than its 2.0 | L |
| **R10** | **Macros on descriptors.** A consumer registers fluent methods on `Descriptor` (as `TypeRegistry` does on `Schema`), typed for PHPStan through the extension; a macro returns a descriptor built with the public modifiers (`when()`…). | JSON:API conditions: `whenInFields()`, `whenInIncludes()`, `can()` | M |
| **R12** | **No `get_object_vars()` per read.** `PlainSourceReader` reads a public property without building the object's property table: the declared public properties listed once per class, a dynamic one through `property_exists()`, `get_object_vars()` kept for the rare cases (uninitialized, lazy, non-public, internal classes), then `__get()` / `__isset()` / `ArrayAccess` as before; same semantics (D88). | `get_object_vars()` on an Eloquent model costs +2.6 KB per model and 3.4 µs instead of 1 µs per subject of two fields; `laravel-json-api` drops its `ModelSourceReader` once released | S |
| **R13** | **A plan keeps paths.** `Projector::plan()`'s `$keys` may hold dotted paths of head keys (`address.city`, `tags.*.name` through the items of a list): a member left out is never evaluated (source, presences, normalization, conversions, value errors); the shorter path wins; an unknown path is ignored (D89). | JSON:API sparse fieldsets naming members of an object; `laravel-json-api` drops its `MemberFieldsets` (projecting the whole object, then filtering) | S |
| **R14** | **Date ↔ date-time convert automatically.** A field that was a date and is now a date-time (or the reverse) needs no `down:` / `up:` and no `conversion-missing` (D90). | `laravel-json-api` declares identity `down:` closures for `born_at` | S |
| **R15** | **A checker on some classes.** A consumer checking a subset of the classes (`jsonapi:check`) gets the checker the provider binds, with its own finder, on a subset of the classes (a locked class not given is not reported gone), instead of rebuilding the list of checks (D91). | `laravel-json-api` copies the provider's list and diverges silently | S |
| **R16** | **Public pattern conversions.** `Export\RegexPattern::of()` and `Export\DatePattern::of()` are public contract: a generator gets a string pattern's `pattern` as `JsonSchemaExporter` writes it, and a date format's when JSON Schema does not name it (not `Y-m-d`, RFC 3339 or RFC 3339 extended, which export `format`), `null` when there is none (D92). | `laravel-openapi-generator` re-derives them and may drift | S |
| **R17** | **Conditions before the value.** `when()` is evaluated before the source is read: a field refused is never read, normalized, projected nor converted (D93). | `laravel-json-api` 2.0 already resolved a member only once rendered (conditions, then value) | S |
| **R4** | **Custom types with identity conversion.** A custom type whose `was(schema:)` is another instance of the same custom type (another fragment) plans no conversion step when the type declares it (`DeclaresConversions`), and gets no `conversion-missing`. The fragment change still shows in the lock, the export and the changelog. | a relationship whose related resource changes | S |
| **R5** | **Inert without configuration** (decided as an implicit one-case revision, D80). With `revisions.enum` left `null`, the provider registers, `optimize` succeeds (`revision:cache` writes nothing), and no command or binding throws until a revision is actually used. | `laravel-json-api` requires this library for applications that declare no revision | S |
| **R6** | **Checks contributed by a consumer.** A package registers `Checking\Check`s (container tag or a `RevisionsConfig` key) run by `revision:check` and `assertRevisionsAreConsistent()`. | JSON:API rules: one name space for attributes and relationships, reserved names | S |
| **R7** | **Export for a generator.** `JsonSchemaExporter::export()`, the structure of `revision:export` and `Changelog::changes()` / `markdown()` are named public contract in `architecture.md` §4 (semver from 1.0). A programmatic way to list the discovered classes with their kinds (`ClassFinder`) is part of it (→ `Checking\Declarations`, model.md D84). | X2 | S |
| **R8** | **Cheap shape lookup.** `Shapes::for()` on a cached class does one array read and at most one `unserialize`. Asserted by the benchmark (a new scenario: shape lookups only). | `laravel-json-api` performance contract (cold start) | S |

Not required (handled by the consumer): the request in conditions (captured in closures when the declaration runs),
relationships (`RelationshipSchema` is a descriptor of `laravel-json-api` extending `Descriptor`),
an adapter for the 2.1 declarations (2.1 is never tagged).

## 3. Questions

| # | Question | Proposal |
|---|---|---|
| CQ1 | Which requirements before the 1.0.0 tag? | R2 (touches `Shapes`, the lock format and the changelog) and R9 (changes how `RevisionedResource` runs `toArray()`): before. The others are additive: 1.1 is possible. |
| CQ5 | With R9, a plain value in `toArray()` (`EagerSchema`, `model.md` D61) is evaluated once, not per subject. | Either such a class keeps the per-subject path (slower, reported by the check), or plain values are refused. |
| CQ2 | R2 as an interface or as attributes? | An interface on the `Revisioned` class: no reflection, and a consumer maps its own attributes to it. |
| CQ3 | Does the lock record a class absent from a revision, or leave it out? | Leave it out: a file lists the classes existing at its revision. |
| CQ4 | `model.md` §12–§13 describe `laravel-json-api` 2.1 and a "3.0" replacing its describer by `Schema`. | Rewrite both sections from this document once decided: the façade stays, 2.1 was never released. |
