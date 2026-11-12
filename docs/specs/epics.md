# `ark4ne/laravel-api-revisions` v1 — implementation plan

> Epics and user stories to deliver v1.0.0. The behaviour every story implements is
> [`model.md`](model.md); the code organisation is [`../architecture.md`](../architecture.md). Progress is tracked in
> §8 of this file.

## How to read this plan

- §1 is the Definition of Done shared by every story.
- §2 lists the epics and their stories: a goal, acceptance criteria, the red tests to write first, the dependencies.
- §3 gives the order and the milestones, §4 the external epics, §5 later work, §6 the questions, §7 the risks.
- §8 is the **tracking board**: the single place where a story's status lives.

Sizes: **S** ≤ 1 day, **M** 2–3 days, **L** 4–5 days. Story ids are stable: `US <epic>.<n>`; never renumber, append.

---

## 1. Definition of Done (every story)

1. **Contract first**: signatures and PHPDoc with neutral bodies, then red tests, then the implementation.
2. Unit tests: one test class per class; test names are sentences. Feature tests on the fixture application when the
   story touches Laravel.
3. Every acceptance criterion has at least one test; the tests listed in the story exist under those names (or
   better ones, recorded in the story's "Done" note).
4. `composer ci` green: PHPStan max with an **empty baseline**, phpat (layers, pure core, `final`, traits), PER-CS.
5. `composer test:coverage` ≥ 90 %; Infection MSI ≥ 80 % on the new classes.
6. `CHANGELOG.md` updated under `[Unreleased]` for every public change.
7. `docs/architecture.md` / `model.md` updated when the story adds a rule, a pattern or changes a decision.
8. No static state outside `Support\Memo`, `RevisionScope::provider()` and `Schema\TypeRegistry` (D42).
9. §8 updated: status `done`, and a **Done** note under the story (what was built, deviations, follow-ups).

---

## 2. Epics

### E0 — Bootstrap and quality gates

Goal: an empty package where every safeguard of `architecture.md` §19 already runs, so the first line of code is
checked.

#### US 0.1 — Package skeleton · S

*As a maintainer, I want the package skeleton, so that it installs and its test suite runs.*

- `composer.json`: `ark4ne/laravel-api-revisions`, `Ark4ne\ApiRevisions\` → `src/`, `Test\` → `tests/`, PHP `^8.3`,
  `illuminate/{support,http,validation,routing,console,contracts}` `^12.46 || ^13.0`, dev tools as in
  `laravel-json-api`, scripts `test`, `test:coverage`, `stan`, `stan:baseline`, `cs`, `cs:fix`, `mutation`,
  `fixtures:update`, `benchmark`, `ci` with descriptions, provider auto-discovery.
- `phpunit.xml.dist` (suites Unit, Feature), `.editorconfig`, `.gitattributes` (export-ignore of dev files),
  `.gitignore` (`vendor/`, `build/`), `LICENSE` (MIT), `README.md` stub, `CHANGELOG.md` (`[Unreleased]`),
  `UPGRADE.md` stub, `CONTRIBUTING.md`.
- `tests/TestCase.php`, `tests/FeatureTestCase.php` (Orchestra Testbench), one smoke test.

Tests: `test_the_package_boots_in_a_laravel_application`.

**Done** (2026-11-11): `composer.json` (illuminate `^12.46 || ^13.0`, dev tools of `laravel-json-api`, every script with
its description, auto-discovery of `Laravel\RevisionsServiceProvider`, empty for now), `phpunit.xml.dist` (Unit,
Feature), `tests/TestCase` (pure PHP), `tests/FeatureTestCase` (Testbench, loads the providers declared in
`composer.json` `extra.laravel.providers`, so the smoke test checks the real auto-discovery; `application()` helper as
Testbench types `$app` nullable), `tests/Feature/PackageTest`, repository files. Deviations: `composer.lock` is
git-ignored (a library; CI resolves lowest / highest); `phpstan.neon.dist` minimal (level max + Larastan) until
US 0.2; the scripts `test:coverage` and `benchmark` reference `bin/coverage-check.php` (US 0.2) and `bin/benchmark.php`
(US 5.4); `cs` has no configuration until US 0.2.

#### US 0.2 — Static analysis and style · S

- `phpstan.neon.dist`: level max, Larastan, strict rules, phpstan-phpunit, phpat, disallowed calls (facades and
  helpers allowed in `src/Laravel/*`, `tests/*`, `config/*`; debug output forbidden everywhere); empty
  `phpstan-baseline.neon` with the "never add an entry" comment.
- `.php-cs-fixer.dist.php` (PER-CS + the rules of `laravel-json-api`), `infection.json5` (MSI 80),
  `bin/coverage-check.php` (90 %).

Tests: `composer ci` green on the skeleton.

**Done** (2026-11-11): `phpstan.neon.dist` (level max, Larastan, strict rules, phpstan-phpunit, phpat extension,
disallowed calls), empty `phpstan-baseline.neon`, `.php-cs-fixer.dist.php`, `infection.json5`, `bin/coverage-check.php`.
Each gate proven on a temporary violating file (not committed): a facade, `dd()`, `config()`, `app()`, `data_get()`,
`value()`, `Container::getInstance()` and `==` in the core rejected by PHPStan, a style fault by the fixer, the same
helpers accepted in `src/Laravel/`; coverage gate exits 1 under its threshold. Deviations: the disallowed lists go
beyond `laravel-json-api` (the `Illuminate\Support` helpers and the container: the core is pure, and phpat does not
see function calls); the "never add an entry" comment sits in `phpstan.neon.dist` (`stan:baseline` rewrites the
baseline file); `ignoreMsiWithNoMutations` so a change without mutable code passes; the fixer only scans existing
folders. Infection is installed globally but not on the `PATH` of this machine (`~/.composer/vendor/bin`).
Follow-ups: `config` in the PHPStan `paths` (US 8.1), `bin/benchmark/*` in the `allowIn` of facades (US 5.4).

#### US 0.3 — Architecture test · S

*As a maintainer, I want the layers enforced by the analysis, so that no story can break them silently.*

- `tests/Architecture/ArchitectureTest.php` (phpat): layers of `architecture.md` §2 left to right; `Exceptions`
  depends only on `Support`; **no layer left of `Laravel` depends on `Illuminate`**; classes `final` except the
  allowed base classes; traits only in the allowed list.
- An empty namespace per layer is fine; each rule is proven by a temporary violating class during the story (not
  committed).

**Done** (2026-11-11): `tests/Architecture/ArchitectureTest.php` (phpat, registered in `phpstan.neon.dist`): layers
left to right, `Exceptions` on `Support` only, pure core (no `Illuminate` in `Support` … `Checking`, `Exceptions`),
`final` classes, abstract only for `BASE_CLASSES`, no package class using a trait, `Definition` classes `readonly`,
interface names. Every rule proven on a temporary violating class; `Schema\Descriptor` abstract and `IsRevision`
not reported. Deviation: phpat `shouldNotExist()` never fires on a trait (PHPStan analyses a trait only in the context
of its users), so the allowed traits are checked by `tests/Unit/Architecture/DeclaredTraitsTest.php`, a tokenizer
over `src/` (proven red on an unlisted trait; its parsing covered by a data provider). Added beyond the story: the
`readonly` rule of `Definition` (architecture.md §4). Consequence recorded in architecture.md §14: exceptions take
`\BackedEnum` / `string`, never `Revision`, and their HTTP rendering lives in the Laravel layer. Follow-up: Q9.

#### US 0.4 — Continuous integration · S

- `.github/workflows/ci.yml`: tests (PHP 8.3/8.4/8.5 × Laravel 12/13 × lowest/highest), static (validate, PHPStan,
  CS), coverage (pcov, gate 90 %), mutation (blocking on `master`), audit (informative); `codecov.yml`.

**Done** (2026-11-11): `.github/workflows/ci.yml` — `tests` (PHP 8.3/8.4/8.5 × Laravel `^12.46`/Testbench `^10.0` and
`^13.0`/`^11.0` × lowest/highest, `audit.block-insecure` off so "lowest" really installs the declared minimum),
`static` (validate, PHPStan, CS on 8.4), `coverage` (pcov, `bin/coverage-check.php` 90 %, Codecov upload),
`mutation` (Infection 0.35.8 pinned, blocking on `main` only), `audit` (informative); concurrency cancels superseded
runs except on `main`. `codecov.yml`: project target 90 %, patch informational. CONTRIBUTING.md says a (draft) PR runs
the CI of a story branch. The four Laravel × dependencies combinations were replayed locally on PHP 8.5: all green
(Laravel 12 lowest prints two vendor Guzzle deprecations, exit 0). Deviations: no GitHub remote yet, so the workflow
has never run on GitHub; mutation is blocking on `main` (the default branch), not `master`; no `pdo_sqlite` extension
(nothing uses a database yet).

---

### E1 — Revisions

Goal: an application declares its revisions as an enum and every part of the library compares them the same way.

#### US 1.1 — Revision contract and order · M · depends US 0.3

*As an application developer, I want to declare my revisions as an enum with an explicit order, so that every
declaration and every client is compared on one timeline.*

- `Revisions\Revision extends \BackedEnum` with `static order(): list<static>` and the methods of `IsRevision`.
- `Revisions\RevisionOrder` (`final`, pure, memoized per enum class): `index()`, `compare()`, `previous()`, `next()`,
  `latest()`, `oldest()`, `between(from, to)` (inclusive, ordered).
- `Revisions\IsRevision`: `isBefore()`, `isAfter()`, `isAtLeast()`, `isAtMost()`, `previous()`, `next()`,
  `latest()`, `oldest()` — all delegating to `RevisionOrder`.
- Comparing cases of two enums: `DefinitionException::mixedRevisions()`. An `order()` missing a case or listing one
  twice: `DefinitionException::invalidOrder()` at first use (the check also reports it, US 9.2).
- Fixture `tests/Fixtures/ApiRevision` (`Genesis`, `Winter25`, `Spring26`, `Atlas`), declared in a non-alphabetical
  case order to prove that `order()` wins.
- Spike M0 findings to address: F4, F12 (§3.1).

Tests: `test_revisions_are_ordered_by_their_order_method_not_by_declaration`,
`test_the_head_is_the_last_revision_of_the_order`, `test_previous_of_the_oldest_revision_is_null`,
`test_comparing_revisions_of_two_enums_throws`, `test_an_order_missing_a_case_throws`.

**Done** (2026-11-11): `Revisions\Revision` (contract: `order()` and the methods of `IsRevision`),
`Revisions\IsRevision`, `Revisions\RevisionOrder` (the order checked on first use and memoized per enum, cases keyed
by **name**: int-backed enums whose values do not follow the order work, F4), `Exceptions\RevisionsException`
(marker), `Exceptions\DefinitionException` (`mixedRevisions(\BackedEnum, \BackedEnum)`, `invalidOrder(class,
reason)`, private constructor), `Support\Memo` (`remember()`, `flush()`, flushed after every Unit test by
`tests/TestCase`). Fixtures `tests/Fixtures/ApiRevision` (cases declared out of order) and
`tests/Fixtures/Revisions/` (`IntRevision`, and invalid orders: missing, duplicate, foreign case, not a list, empty;
F12). `tests/Types/RevisionTypes.php` asserts the types applications see. Coverage 100 %, Infection MSI 95 % (3
equivalent mutants: `between()` length `> 0` / `>= 0`, the memo key). Deviations: `Support\Memo` and the
`RevisionsException` marker are created here (needed first by this story); `invalidOrder()` takes a reason, and also
rejects a foreign case, an order that is not a list and an empty order; `RevisionOrder::previous()`, `next()`,
`between()` return `Revision` rather than a template (PHPStan binds a template, and `static` on an enum, to the case
passed), `IsRevision` narrows to `self` (architecture.md §3); `between(from, to)` is empty when `from` is newer than
`to` (not decided by model.md: a pure helper's choice, to confirm).

#### US 1.2 — Revision metadata · S · depends US 1.1

- Attribute `Revisions\Meta(string $description = '', ?string $publishedAt = null, ?string $deprecatedAt = null,
  ?string $sunsetAt = null)`, `TARGET_CLASS_CONSTANT`; dates validated as `Y-m-d` at read time.
- `IsRevision::meta(): Meta` reads it by reflection, memoized; an empty `Meta` when absent.
- `Meta::isDeprecated(DateTimeInterface $now)`, `isSunset($now)`.

Tests: `test_meta_reads_the_attribute_of_the_case`, `test_a_case_without_meta_has_an_empty_meta`,
`test_an_invalid_date_in_meta_throws`.

**Done** (2026-11-11): `Revisions\Meta` (`final readonly` attribute, `TARGET_CLASS_CONSTANT`; its `Y-m-d` days are
checked in the constructor, i.e. when the attribute is read: strict round trip, `2026-02-30` refused; dates stay
strings), `isDeprecated($now)` / `isSunset($now)`; `Revisions\MetaReader::of()` (`@internal`, reflection, memoized
per case) behind `Revision::meta()` / `IsRevision::meta()`; `DefinitionException::invalidMetaDate()` and
`invalidMeta(case, previous)`, which names the case for a bad date and also for the PHP `Error`s of a broken
declaration (repeated attribute, wrong argument type). The reference fixture `ApiRevision` carries the `#[Meta]` of
model.md §2.1. Coverage 100 %, Infection MSI 94 % (2 mutants on the unused exception code). Deviations: the "day
reached" rule (from the day included, in the timezone of `$now`) is not decided by model.md: applied and recorded
as Q10, with the instant of the `Deprecation` / `Sunset` headers left to US 8.x.

#### US 1.3 — Current revision and `using()` · S · depends US 1.1

*As an application developer, I want `ApiRevision::current()` and `ApiRevision::using()`, so that code outside a
request (webhooks, jobs) renders in a chosen revision.*

- `Revisions\RevisionScope`: base revision + `using()` stack; `current()` = top of stack, else base, else
  `latest()`. `using()` restores the previous state in `finally` and returns the closure's result.
- `RevisionScope::provider(Closure(): RevisionScope)` set once at boot; default: one process-local scope.
- `IsRevision::current()` / `using()` delegate to the provided scope. `current()` returns a case of the calling enum
  (`DefinitionException::mixedRevisions()` otherwise).

Tests: `test_current_is_the_head_by_default`, `test_using_sets_the_revision_inside_the_closure_only`,
`test_using_restores_the_revision_when_the_closure_throws`, `test_nested_using_restores_each_level`.

**Done** (2026-11-11): `Revisions\RevisionScope` (instance: a base revision, `setBase(?Revision)`, under a `using()`
stack popped in `finally`; `current(class)` = innermost `using()`, else base, else the head of `class`), one static
`provider(?Closure)` (`null` restores a new process-local scope, `get()` installs it lazily);
`Revision::current()` / `using()` in the contract and `IsRevision`. `tests/TestCase` and `tests/FeatureTestCase`
reset the provider and `Memo` after every test. Coverage 100 %, Infection MSI 100 % on `RevisionScope` and
`IsRevision`. Deviations: a revision of another enum throws the new `DefinitionException::foreignRevision(case,
expected enum)` rather than `mixedRevisions()` (which keeps comparisons); `provider()` is replaceable (Testbench
boots an application per test), not enforced "once"; the revision does not outlive the `using()` closure (documented
in model.md §10). Follow-up: Q11 (the fallback outside a request: head or configured default, for US 8.1).

---

### E2 — Schema vocabulary

Goal: one typed, immutable, lazy vocabulary (`Schema::…`) describing a field at the head.

#### US 2.1 — Descriptor, source and scalar types · M · depends US 0.3

- `Definition\TypeDefinition`, `Definition\Kind`; `Schema\Descriptor` (abstract): `Source`, `nullable()`,
  `required()`, `sometimes()`, `describe()`, `example()`, `deprecated()`, `rules(...)` (opaque), `toType()`.
- `Schema\Source`: `key()` (dotted paths), `closure()`, `declaredKey()`; never an evaluated value (a non-closure,
  non-string source throws `DefinitionException::eagerSource()`).
- `Support\Invoke::value(Closure, $value, $subject)`: the single invoker of every closure, signature
  `($value, $subject)` (`model.md` §3, D18); closures declaring fewer parameters work.
- `Schema::string()`, `int()`, `float()`, `bool()` and their modifiers (`min`, `max`, `format`, `pattern`).
- `Descriptor::normalize()` / `serialize()` for the four scalars, per the table of `model.md` §6.3; a refused value
  returns a `ValueError` (never throws: the handler decides, US 6.4).
- Every modifier returns a new instance.

Tests: `test_a_modifier_returns_a_new_descriptor`, `test_a_descriptor_without_source_reads_its_declared_key`,
`test_an_evaluated_value_is_refused_as_source`, `test_string_schema_describes_its_constraints`,
`test_int_schema_normalizes_an_integral_numeric_string`, `test_int_schema_refuses_a_fractional_float`,
`test_bool_schema_accepts_zero_and_one`. Types:
`tests/Types/ScalarSchemaTypes.php` asserts `BoolSchema` has no `min()`.

**Done** (2026-11-11): `Definition\Kind` (every kind of architecture.md §4), `Definition\TypeDefinition` (`final
readonly`, `of()`, `with*()`, `addExample()`); `Schema\Descriptor` (abstract extension base, `Source`, `nullable()`,
`required()`, `sometimes()`, `describe()`, `example()`, `deprecated()`, `rules()`, final `toType()` over the
protected `type()`), `Schema\Source` (`from()`, `key()` with dotted paths, `closure()`, `declaredKey()`),
`StringSchema`, `IntSchema`, `FloatSchema`, `BoolSchema`, the `Schema` factory; `Support\Invoke::value()`;
`DefinitionException::eagerSource()` / `invalidSourceKey()`. `tests/Types/ScalarSchemaTypes.php` (the ignore on
`BoolSchema::min()` fails if the error disappears; typed source closures accepted). Coverage 100 %, Infection MSI
93–98 % (equivalent mutants: rounding variants of `floor`, unreachable cast bounds, `%G` digits). Deviations:
- the refusal is `Schema\InvalidValue(value, expected kind, cast)`, not `ValueError` (PHP's `\ValueError` is thrown
  by `BackedEnum::from()`); the type computes the lenient cast (`null` when none makes sense) and US 6.4 renders it;
- the factories take a native `mixed` source (PHPDoc `string|Closure|null`): in a file without `strict_types` PHP
  would coerce `Schema::int($order->total)` into the key `'1250'` (`tests/Fixtures/NonStrictDeclarations.php`,
  excluded from the fixer);
- the `Descriptor` constructor is not `final` (composites and custom types take their own arguments first);
- closure parameters are typed as a plain `Closure` (`Closure(mixed, mixed)` refuses `fn (int $cents)`); a
  first-class callable of an internal function must be wrapped (documented on `Invoke`);
- a float normalized to a string keeps every digit whatever the `precision` setting; INF / NaN refused;
- the numeric string rules are applied and recorded as Q12, to confirm.

#### US 2.2 — Dates and enums · S · depends US 2.1

- `Schema::date()`, `dateTime()` (format from `Configuration`), `Schema::enum(class-string<BackedEnum>)` (values and
  backing type in its `TypeDefinition`).
- Normalization: dates from `DateTimeInterface`, parsable string or timestamp → `DateTimeImmutable`, serialized with
  the type's format; enums from the case or its backed value (`::from()`) → the case, serialized as `->value`.

Tests: `test_enum_schema_exports_the_values_of_the_backed_enum`, `test_date_schema_uses_the_configured_format`,
`test_enum_schema_normalizes_a_backed_value_to_its_case`, `test_date_schema_normalizes_a_timestamp`.

**Done** (2026-11-11): `Configuration\Configuration` (`dateFormat` `Y-m-d`, `dateTimeFormat` RFC 3339), decision D29
taken with the maintainer: the format is resolved at compilation through `Descriptor::configure(Configuration)`
(identity by default), never read globally; `DateSchema` / `DateTimeSchema` (`format()`, else configured, else the
`Configuration` default, the same in `toType()` and `serialize()`), `Schema\Dates` (`@internal` normalization),
`EnumSchema<TEnum>` (`Schema::enum(class, $source)`; a non-backed class throws `DefinitionException::notBackedEnum()`;
its type exports `enum`, `backing`, `values` through the new `TypeDefinition::withEnum()`). Coverage 100 %, Infection
MSI 98 % (one equivalent mutant on the warning count). Deviations and follow-ups: the rules beyond model.md §6.3
(impossible dates refused, blanks refused, relative strings accepted, int-backed enums reading what `int` reads) are
recorded as Q13; the compiler calling `configure()` is added to US 5.1 (and its forwarding to US 2.3, its cache
impact to US 5.3, the format validation to US 8.1).

#### US 2.3 — Lists and objects · M · depends US 2.1

- `Schema::list(Descriptor $items, $source = null)` (`min()` / `max()` on the size; any `iterable` normalized to a
  list), `Schema::object(array<string, Descriptor>, $source = null)`, nested freely; children keep their own history
  (E3).
- Subject of the children (`model.md` §6.1): an object with a source → the source's value; without → the parent's
  subject; a list → each item.
- `ListSchema` / `ObjectSchema` override `configure()` to forward it to their items and children (D29, from US 2.2).

Tests: `test_an_object_describes_its_children`, `test_a_list_describes_its_items_and_size`,
`test_a_list_normalizes_any_iterable`.

**Done** (2026-11-11): `Schema\ListSchema` (`Schema::list($items, $source)`, `min()` / `max()` on the size, any
`iterable` normalized to a list of its elements), `Schema\ObjectSchema` (`Schema::object($children, $source)`, children
checked at declaration: `DefinitionException::invalidChildKey()` / `invalidChild()`; serialized as `{}` when empty),
both forwarding `configure()`; `Source::parent()` (an object without source reads its children on the parent's
subject); `TypeDefinition` gains `items` / `children` (its withers now go through one private copier). Decisions taken
with the maintainer: **D34** (composites: `normalize()` checks the container, the projection projects the children
first, the composite's `when()` / `down:` receive the projected children, `serialize()` the serialized ones) and
**D35** (lenient: a refused list or object renders `null`). `Descriptor`'s template is now covariant
(`Descriptor<string>` is a `Descriptor<mixed>`), so `serialize()` takes a `mixed` and date / enum `serialize()` pass
another type through. Coverage 100 %, Infection MSI 96–100 %. Deviations and follow-ups: the subject of the children
(criterion 2) is only representable here (`Source::parent()`), its behaviour is tested by US 6.2; children are
`TypeDefinition`s without required / nullable / history until US 3.4 (noted there and on US 10.1); size bounds
(`min(-1)`, `min > max`) are not validated, as for strings (left to the check, US 9.2).

#### US 2.4 — Escape hatch and conditions · S · depends US 2.1

- `Schema::rule(array $rules)` → `Kind::Any` unless `describe()`; `Schema\DescribesSchema::toSchema(): array` on a
  rule enriches the export (read by US 10.1).
- `->when(fn ($value, $subject) => …)`: the field becomes `conditional` (optional in the export); `->whenHas()`: a
  presence condition (runs before the source).

Tests: `test_a_rule_schema_is_any_without_description`, `test_a_conditional_field_is_marked_conditional`.

**Done** (2026-11-11): `Schema\RuleSchema` (`Schema::rule($rules, $source)`, kind `Any`, any value normalized and
serialized as it is, `null` refused like every descriptor; constructor rules kept before those of `rules()`, keys
dropped), `Schema\DescribesSchema`, `Schema\Presence` (a value: `null` path for the field's own key, or a path;
`DefinitionException::invalidPresenceKey()`), `Descriptor::when()` / `whenHas()` / `isConditional()` /
`conditions()` / `presences()` (declared, never run), `TypeDefinition::$jsonSchema` / `withJsonSchema()`.
`Descriptor::toType()` merges the keywords of every `DescribesSchema` rule, on any descriptor
(`DefinitionException::invalidJsonSchema()` unless JSON-ready). Coverage 100 %, Infection MSI 100 % on the new code.
Deviations and follow-ups: the criteria "optional in the export" and "runs before the source" are tested only as
descriptor state, their behaviour belongs to US 3.4, US 6.1 and US 10.1 (noted there); questions Q14
(`DescribesSchema` scope and precedence) and Q15 (default key of `whenHas()`), decided as D36 and D37; eager values are not a `RuleSchema`
(noted on US 8.5).

#### US 2.5 — Custom types · M · depends US 2.3, US 4.1

*As an application developer, I want to add my own type (`Schema::money()`), so that it carries its rules, its
documentation and its conversions everywhere.*

- `Schema\TypeRegistry`: `register(string $name, class-string<Descriptor>)`; `Schema::__callStatic()` serves it;
  unknown name → `DefinitionException::unknownType()`.
- A custom type declares its `TypeDefinition` (`Kind::Custom` + name + JSON Schema fragment), its rules and,
  optionally, its converters (US 4.4).
- Fixture `tests/Fixtures/MoneySchema` (`amount` int cents + `currency`).

Tests: `test_a_registered_type_is_available_on_schema`, `test_an_unknown_type_throws`.

**Done** (2026-11-11): `Schema\TypeRegistry` (static, decided with the maintainer: **D42**; `register()` checks the
name is a PHP method name `Schema` does not declare and the class extends `Descriptor`, `has()`, `make()` with
positional or named arguments, `flush()` called by both test bases), `Schema::__callStatic()`,
`TypeDefinition::custom($name, $fragment)` (`$name`, `$fragment` kept apart from the rule keywords of `$jsonSchema`,
so `schema-override` (US 9.2) and the shared export (US 10.2) still see each side; empty name and non JSON-ready
fragment refused), `Support\Json::isReady()`, the fixtures `Money` / `MoneySchema` (rules through
`declaredRules()`). The registered name is the factory's (case-sensitive, like Laravel's macros); the type's own
name is the one `custom()` declares; abstract classes are refused. `CustomTypeTest` tests the behaviour of a custom
type end to end on the fixture (no class of its own). Coverage 100 %, Infection MSI 100 %. Deviations: the converters of a custom type stay in US 4.4 (story criterion "optionally");
`Schema::money()` is unknown to PHPStan in applications (US 2.7 appended); the Laravel layer registers nothing yet:
an application calls `TypeRegistry::register()` in its provider's `boot()` (a `types` configuration key could come
with US 8.1).

#### US 2.6 — Nested `Revisioned` classes · S · depends US 2.3, US 5.1

*As an application developer, I want a field to be another resource (`'customer' => Schema::resource(CustomerResource::class)`),
so that nested objects follow their own history at the same revision.*

- `Schema::resource(class-string<Revisioned>, $source = null)` → `ResourceSchema` (`Kind::Object`, reference to the
  class); a class that is not `Revisioned` throws `DefinitionException::notRevisioned()`.
- Its type is the `Shape` of the other class at the same revision (resolved by the compiler, never inlined in the
  descriptor); cycles (`Order → Customer → Order`) are allowed because shapes are referenced, not copied.
- A list of resources: `Schema::list(Schema::resource(LineResource::class), 'lines')`.

Tests: `test_a_nested_resource_uses_its_own_shape_at_the_same_revision`, `test_a_resource_cycle_compiles`,
`test_a_non_revisioned_class_is_refused`.

**Done** (2026-11-12): `Schema\ResourceSchema` (`Schema::resource()`, `class()`; `notRevisioned()` for another
class; normalizes and serializes as an object), `TypeDefinition::$resource` / `withResource()` (an `Object` kind
referencing the class, no children: never a copy of its shape), `Compiler` checking every resource nested in a field's
type (items and children included) without compiling it. Decision taken with the maintainer: **D49** (the nested
shape is the class's declaration of the same kind as its parent, at the same revision; the compiler refuses a class
not declaring it, `undeclaredKind()`). Fixtures: `CustomerDeclaration` (response and body; its `orders` list back to
`OrderDeclaration`: the cycle), `MisnestedDeclaration`, `ForeignResourceDeclaration`; `customer` added to
`OrderDeclaration` and `StoreOrderDeclaration`. Coverage 99.89 %, Infection MSI 100 % on the changed classes after the
fix of one mutant. Deviations: the nested shape is resolved by compiling the referenced class (`Shapes::for()` is
US 5.2); `ConversionChain` treats two different referenced classes as other values (a converter is needed);
the compiler also throws `notRevisioned()` for a custom descriptor whose type references another class. Review: the
architecture no longer cites `Shapes::for()` as existing, CHANGELOG wording, `readonly` class. Follow-ups: US 6.2
projects a nested resource with its shape; US 9.2 reports `unknown-resource` from these exceptions (D39); US 10.2
exports a nested resource as a `$ref`.

#### US 2.7 — Custom types seen by PHPStan · S · depends US 2.5

*As an application developer, I want `Schema::money()` typed by PHPStan, so that a custom type is as safe as a
built-in one.*

- A PHPStan extension shipped with the package (`DynamicStaticMethodReturnTypeExtension` / `MethodsClassReflectionExtension`
  on `Schema`) reading a name → class map from the application's PHPStan configuration; documented in the README.

Tests: `tests/Types/CustomTypeTypes.php` asserts `Schema::money()` is a `MoneySchema`; an unknown name is reported.

**Done** (2026-11-11): `extension.neon` (parameter `apiRevisions.types`, name → class; listed in `extra.phpstan` for
`phpstan/extension-installer`), `PHPStan\CustomTypesExtension` (a `MethodsClassReflectionExtension` on `Schema`: a
mapped name of an existing, concrete `Descriptor` becomes a public static method with the constructor's variants,
returning the class), `PHPStan\CustomTypeMethod`. The package's own `phpstan.neon.dist` includes the extension and
maps `money` to the fixture, so the `@phpstan-ignore` of US 2.5 are gone. Tests: `tests/Types/CustomTypeTypes.php`
(analysed) and `tests/Unit/PHPStan/CustomTypesExtensionTest` (PHPStan's `TypeInferenceTestCase`, run by PHPUnit:
`phpunit.xml.dist` raises `memory_limit` to 512M for its container). Coverage 100 %, Infection MSI 95 %. Deviation:
only `MethodsClassReflectionExtension` was needed (the method reflection carries the return type); the map is
declared by hand, PHPStan cannot read the runtime `TypeRegistry`; `SchemaTest::test_an_unknown_type_throws` now calls
`Schema::percent()` (`money` is known to PHPStan); a generic descriptor is typed as its raw class (template types not
resolved, documented); `composer.json` declares `conflict: phpstan/phpstan <2.0` and suggests
`phpstan/extension-installer`; two phpat rules keep `src/PHPStan/` out of run time and dependent on `Schema` only.

---

### E3 — History: `since`, `until`, `was`

Goal: the history of a field is declared on it, and one pure function turns the head into the shape of any revision.

#### US 3.1 — `since()` and `until()` · S · depends US 1.1, US 2.1

- `Descriptor::since(Revision, mixed $default = new Undefined())`, `until(Revision)`; `Definition\Lifetime`
  (`covers(Revision)`, inclusive bounds).
- `since` newer than `until` on one descriptor: `DefinitionException::invertedLifetime()`. A second call keeps the last
  and records a duplicate (reported by US 9.2).

Tests: `test_until_keeps_the_field_up_to_its_revision_included`, `test_since_keeps_the_field_from_its_revision_included`,
`test_since_after_until_throws`.

**Done** (2026-11-11): `Definition\Lifetime` (`always()`, `withSince()` / `withUntil()`, `covers()`, inclusive
bounds, `DefinitionException::invertedLifetime()`, revisions of another enum refused), `Descriptor::since(Revision,
$default)` / `until(Revision)` / `lifetime()` / `default()` / `repeatedBounds()` (each extra call with the revision it
replaced), `Support\Undefined` (`is()`) brought forward from US 3.5. A later `since()` without default leaves none,
`until()` keeps it (written in model.md §4.4 rule 5). Coverage 100 %, Infection MSI 100 %. Deviations and follow-ups:
`lifetime` and `default` live on `Descriptor` until US 3.3 gathers them with the `Change`s in `History`
(architecture.md §5); groups (US 3.2) need an internal entry point (noted there); rule 5 gets the `bound-repeated`
code in model.md §9; Q16 (whether the check reports declaration errors, severity of `bound-repeated`).

#### US 3.2 — Groups · S · depends US 3.1

- `Schema::since(R, array $fields, mixed $default = new Undefined())`, `Schema::until(R, array $fields)`: return the
  array with each descriptor updated; a member's own newer `since` wins; an older one throws
  `DefinitionException::sinceOlderThanGroup()`; a member's own default wins.
- Nested in objects: a group inside `Schema::object([...])` works the same.
- Spike M0 findings to address: F10 (Q7) (§3.1).

- Note (from US 3.1): a group must not go through the public `since()` / `until()` of its members, which record a
  repeated bound and replace the member's default: apply it through a dedicated (`@internal`) entry point that keeps
  a newer own `since` and a defined own default, records nothing, and throws `sinceOlderThanGroup()`.

Tests: `test_a_group_sets_since_on_every_member`, `test_a_member_newer_than_its_group_keeps_its_own_since`,
`test_a_member_older_than_its_group_throws`, `test_a_member_default_overrides_the_group_default`.

**Done** (2026-11-11): `Schema::since(R, $fields, $default)` / `Schema::until(R, $fields)` behind the `@internal`
`Schema\Groups`, applied through `Descriptor::inGroup()` (`@internal`, records no repeated bound, callable only from
`Groups`: disallowed-calls rule). A member's own newer `since` / earlier `until` (D24) and own default win; a wider
bound throws `DefinitionException::sinceOlderThanGroup()` / `untilNewerThanGroup()`; members are checked
(`invalidGroupKey()`, `invalidGroupMember()`). Nested groups resolve from the inner one; groups spread in
`Schema::object()` alike. Coverage 100 %, Infection MSI 100 %. Precision written in model.md §4.3: a member without
its own default takes the group's even when it keeps a newer own `since`. Follow-ups: `until-newer-than-group` added
to model.md §9 and to Q16.

#### US 3.3 — `was()` · M · depends US 3.1, US 4.1

- `Descriptor::was(Revision $revision, ?string $name = null, ?Descriptor $schema = null, ?Closure $down = null,
  ?Closure $up = null)` → one `Schema\Change` in the `History`, ordered by revision whatever the call order.
- Two `was()` on the same revision, or a `was()` on the head: `DefinitionException::invalidHistory()`.
- A `schema:` carrying a source: `DefinitionException::sourceInHistory()` (older values derive from the head value).
- No effect on the head: the descriptor describes itself exactly as without `was()`.
- Spike M0 findings to address: F10 (§3.1).

Tests: `test_was_returns_a_new_descriptor`, `test_was_entries_are_ordered_by_revision_not_by_call`,
`test_was_on_the_head_throws`, `test_was_has_no_effect_on_the_head_type`.

**Done** (2026-11-11): `Schema\Change` (one `was()`: revision, name, schema, `down`, `up`; never a source),
`Schema\History` (`none()`, `isEmpty()`, `withLifetime()`, `withChange()` keeping the changes newest first and
refusing the head, a repeated revision and bounds or changes of two enums), `Descriptor::was()` / `history()`;
`Descriptor` now keeps its lifetime in its `History` (the US 3.1 follow-up; its `default` stays on `Descriptor`, it
belongs to `since()`, not to the history). `Change` and `History::withChange()` are `@internal`: `Descriptor::was()`
checks the arguments. F10 (part): a `was(schema:)` whose schema, items or children declare a source, a lifetime, a
history, a default or a condition throws `sourceInHistory()` (model.md §6.4; an object without source is accepted);
`was(name: '')` throws `emptyHistoryName()`, a `was()` changing nothing `emptyChange()` (both `definition-error`
under D39; `invalidHistory()` is `history-unordered`). Coverage 100 %, Infection MSI 99–100 % (the escaped mutant is
equivalent). Follow-ups: a `was()` older than the `since` is `history-before-since` (US 9.2); one newer than the
`until` is `history-after-until` (D43, US 9.2); `down:` / `up:` become `ClosureConverter`s in US 4.2, chained by US 4.3.

#### US 3.4 — `ShapeSelector`: the shape of a revision · L · depends US 3.2, US 3.3, US 4.3

*As a maintainer, I want one pure function giving the shape of a class at a revision, so that the response, the
validation, the export and the lock never disagree.*

- `Compilation\ShapeSelector::at(array<string, Descriptor> $head, Revision $r): Shape` (`model.md` §4.5): visible
  fields (lifetime), for each: name, type, required / nullable, defaults, conversion plan down and up, with **chained
  inheritance** of omitted `was()` arguments from the next newer shape.
- Recursive into objects and lists (children have their own history). `TypeDefinition::children` holds plain
  `TypeDefinition`s since US 2.3: the shape gives each child its own field definition (name, required, nullable,
  history), and `children` follows (spike F7 compares types through it).
- Name collisions at `R` are recorded on the `Shape` (reported by US 9.2), never thrown.
- Spike M0 findings to address: F4, F5, F6 (Q6) (§3.1).

Tests (the reference table of `model.md` §4.2, one test per row and per column):
`test_the_head_shape_ignores_every_was`, `test_a_rename_applies_up_to_its_revision_included`,
`test_an_omitted_name_is_inherited_from_the_next_newer_shape`,
`test_chained_type_changes_give_the_type_of_the_oldest_applicable_was`,
`test_a_field_until_a_revision_is_absent_from_the_next_one`, `test_nested_children_follow_their_own_history`,
`test_a_name_collision_is_recorded_on_the_shape`.

Follow-up (from US 2.4): `FieldDefinition::$conditional` is read from `Descriptor::isConditional()`, nowhere
recomputed.

**Done** (2026-11-12): `Compilation\ShapeSelector::at($head, $revision, $direction, $converters)` (pure, runs no
source, condition nor converter), `Compilation\Shape` (`revision`, `direction`, `fields` by head key, `added`,
`collisions`; `names()`, `field()`), `Compilation\FieldDefinition` (name, type, nullable at the revision; required,
sometimes, conditional, default, lifetime from the head; `schemaChange`; `versions`; the unbound `ConversionChain`;
`items` under the key `*`; `children` as a nested `Shape`; `versionAt()`), `Compilation\FieldVersion` (the shape at
one `was()`), `Compilation\Collision`. Chained inheritance of `name:` and `schema:`; a chain step crosses the types
of both shapes at their revisions; a `was()` without `schema:` adds a step only through an explicit converter of the
direction (a renamed composite whose children change plans nothing: they carry their own steps); objects and lists
recurse with their own histories, the type at `R` holds the children under their name at `R` (the first one of a
colliding name). Decisions taken with the maintainer: **D45** (`Shape` and `FieldDefinition` in `Compilation`: a
field carries its `ConversionChain`, right of `Definition`) and **D46** (a `was()` schema gives the value — type,
nullable, rules —, the head the presence). Spike findings: **F4** (revisions kept as cases, compared through
`RevisionOrder`, int-backed enum tested), **F5** (fields added after `R` are defined at their `since`, named and
typed there, with their lifetime: the upgrader drops a `since(W)->until(S)` field), **F6** (the type at `R`, the
chains with registry keys and `StepKind`, the `versions` the upgrader renames from). Coverage 99.88 %, Infection MSI
95 % (3 equivalent mutants: `> 0` / `>= 0` on a `since` already covered, `break` / `continue` on changes sorted newest
first, the unreachable throw of a `was()` on the head). Review: a renamed composite whose children or items changed
planned a false missing step (fixed, three tests added); the first child of a colliding name is kept in the type. Deviations: `at()` takes the `Direction` of the declaration
kind (D27, F8: one direction planned per shape, not "down and up") and the `Converters` (built-in by default); the
`Shape` has no `class` yet; the seven story tests exist, the reference table is one data-provider test
(`test_each_row_of_the_reference_table_is_selected`). Follow-ups: US 5.1 maps the declaration kind to the direction,
passes the application's `Converters` and adds `Shape::$class`; US 7.1 builds its steps from `R` to the head by
reading `FieldDefinition::versionAt()` and `ConversionChain::stepOf()` only (no step list on the `Shape`, F6), and the
`added` fields; the Laravel rules read the rules of the descriptor in force through `schemaChange`; the check
(US 9.2) reports `Shape::$collisions` and ignores `required()` / `sometimes()` on a `was()` schema (D46).

#### US 3.5 — Defaults and the `Undefined` sentinel · S · depends US 3.4

- `Support\Undefined` (singleton-free value object, `Undefined::is()`); a field `since(R)` has a default in every
  shape older than `R` (consumed by US 7.1); `Configuration::missingDefault` (`error` | `null`).

Tests: `test_undefined_and_null_defaults_are_distinct`, `test_missing_default_null_option_fills_null`.

Note (from US 3.1): `Support\Undefined` (with `is()`) and `Descriptor::default()` already exist; this story adds their
use in the shapes and `missingDefault`.

**Done** (2026-11-12): `Configuration\MissingDefault` (`Error`, `Null`), `Configuration::$missingDefault` (`Error` by
default), `ShapeSelector::at(..., ?Configuration $configuration)` resolving `FieldDefinition::$default`: the
`since()` default, else `null` for a `required()->since()` field when `missingDefault` is `Null`, else an `Undefined`;
recursively in objects and lists. A field `since(R)` has its default, at its `since`, in the `added` fields of every
older up shape (US 3.4). Decision taken with the maintainer: **D47** (only `required()` fields receive `null`, nullable or not; an
optional field stays absent; model.md §7.3 reworded). Coverage 99.88 %, Infection MSI 95 % on `ShapeSelector` and
`Configuration` (the 3 equivalent mutants of US 3.4; the default resolution fully killed). Deviations: `missingDefault`
is resolved by the selector, not by `Descriptor::configure()` (composites override it without calling the parent, and
the default of a shape is the selector's decision); the default stays on every `FieldDefinition` of the field, visible
or added. Follow-ups: US 5.1 passes the `Configuration`; US 8.1 maps `missing_default` to `MissingDefault`; US 9.2
`required-since-without-default` reports nothing under `Null`.

---

### E4 — Conversions

Goal: type changes convert automatically when lossless, explicitly otherwise, in both directions.

#### US 4.1 — Converters and directions · M · depends US 2.1

- `Definition\Direction` (`Down`, `Up`); `Conversion\Converter` (`convert()`, `lossless()`, `extraRules()`);
  `Conversion\Converters` registry `(Kind from, Kind to, Direction)` with the built-ins of `model.md` §5, one class each.
- `BackedToEnum` throws `ConversionException::invalidEnumValue()` on an unknown value.
- Spike M0 findings to address: F2 (Q4) (§3.1).

Tests: one per row of the table of `model.md` §5 and per direction, e.g.
`test_int_to_float_is_automatic`, `test_float_to_int_is_automatic_and_flagged_lossy`,
`test_numeric_string_to_float_requires_the_numeric_rule`, `test_an_unknown_enum_value_throws_a_conversion_exception`.

**Done** (2026-11-11): `Definition\Direction`, `Conversion\Converter`, `Conversion\Converters` (immutable registry of
factories `(TypeDefinition $from, TypeDefinition $to): ?Converter` keyed by `Converters::key()`, `builtIn()` in both
directions), twelve built-ins under `Conversion\BuiltIn\`, `Exceptions\ConversionException::invalidEnumValue()`,
`Support\Floats::toString()` (moved out of `StringSchema`, reused by `FloatToString`). Decisions taken with the
maintainer: **D40** (`bool → string` gives `'1'` / `'0'`; `string → bool` as `filter_var(FILTER_VALIDATE_BOOLEAN)`,
else `true`) and **D41** (enum → int only when int-backed; string / int → enum read losslessly as the backing type).
Coverage 100 %, Infection MSI 98–99 % (the escaped mutants are equivalent: an enum type always has its
backing, an earlier arm of `BackedToEnum` already takes the strings of a string-backed enum, the digit search of
`Floats` from 1 or 2). Deviations: `find()` takes the two `TypeDefinition`s, not only their kinds (an
enum target needs its class); the registry key stays `(Kind, Kind, Direction)`. `BackedToEnum` validates (throws) instead of passing
a foreign value through. Follow-ups: chains (US 4.3) store `Converters::key()` and re-resolve with the types of the
shapes; custom types (US 2.5, US 4.4) register factories, and the key must then include the custom type's name (all
custom types share `Kind::Custom`); the up rules of an older shape converted to an enum must carry the enum values
(US 8.6, model.md §5), the converter itself returns no extra rule; on the response side the projection (US 6.1)
maps a `ConversionException` to a value error under `strict_values`.

#### US 4.2 — Explicit casts and closures · S · depends US 4.1

- `Cast::round()`, `floor()`, `ceil()`; `ClosureConverter` wraps `down:` / `up:` (always explicit).

Tests: `test_cast_round_rounds_half_up`, `test_a_closure_converter_is_explicit`.

**Done** (2026-11-12): `Conversion\ExplicitConverter` (marker of the converters an author gives to `was()`, never
`conversion-lossy`), `Conversion\ClosureConverter` (calls its closure `($value, $subject)` through `Support\Invoke`;
lossy, no rule), `Conversion\Cast::round()` / `floor()` / `ceil()` returning a `Conversion\Rounding` (float → int, PHP
`round()` half away from zero), `Support\Floats::toInt()`, `ConversionException::outOfIntRange()`. Decision taken
with the maintainer: **D44** (a float → int conversion out of the int range, `INF`, `NaN` throws; applied to the
built-in `FloatToInt` too). Coverage 99.85 %, Infection MSI 98 % (the escaped mutants are the known equivalent ones of
`Floats::toString()`). Deviations: `Converter::convert()` takes `mixed $subject = null` (every built-in updated; only
a closure reads it); `Descriptor::was()` accepts `Closure|ExplicitConverter` for `down:` / `up:` and wraps a closure,
so `Change::$down` / `$up` are `?ExplicitConverter`, never a bare closure. Follow-ups: chains (US 4.3) reference an
explicit step by its `was` index and direction and pass the subject to `convert()`; the projection (US 6.1) maps
`outOfIntRange()` to a value error like `invalidEnumValue()`.

#### US 4.3 — Conversion chains · M · depends US 4.2

- `ConversionChain` per `(field, revision, direction)`: the ordered steps between consecutive shapes; each step is
  a converter **key** or a closure **reference** (`was` index + direction), so the chain serializes.
- `ConversionChain::bind(History)` re-attaches the closures from fresh descriptors; `apply(mixed): mixed`.
- A step with no converter: the chain records it as **missing** (reported by `conversion-missing`, US 9.2); applying
  it throws `ConversionException::missing()`.
- Spike M0 findings to address: F3, F6, F7, F8 (Q8) (§3.1).

Tests: `test_a_down_chain_converts_from_the_head_to_the_oldest_shape`,
`test_an_up_chain_converts_from_the_client_shape_to_the_head`, `test_a_chain_survives_serialization`,
`test_a_missing_step_is_recorded_not_thrown`.

**Done** (2026-11-12): `Conversion\ConversionChain` (`empty()`, `withStep()`, `bind()`, `apply()`, `missing()`,
`isEmpty()`), `Conversion\ConversionStep` (kind, `Converters::key()`, both `TypeDefinition`s, `was` index, bound
converter), `Conversion\StepKind` (`Registered`, `Explicit`, `Missing`: F6), `ConversionException::missing()`. Every step keeps the
index of the `was()` it crosses (`stepOf()`), so the upgrader (US 7.1) runs an up chain step by step, normalizing to
each shape and passing each step its snapshot (model.md §7.1); `apply()` runs the whole chain, for the down side. A
step serializes without its converter, so a bound chain serializes as its planned form.
Spike findings: **F6** steps carry an enum kind and keys; **F7** steps compare `TypeDefinition`s (kind, enum class,
custom name and fragment, items, children), not kinds; **F8** a chain has one direction, chosen by its caller (D27); **F3** the
chain receives normalized values, normalizing the client's input is left to the upgrader (US 7.1). Coverage 100 % of
the new classes, Infection MSI 100 %. Deviations: `bind(Converters, array $explicit)` instead of `bind(History)`
(`Schema` sits right of `Conversion`; the caller passes the `down` / `up` of each `Change` by index); `apply()` takes
the subject; a chain is built by `withStep()`, the `ShapeSelector` (US 3.4) deciding the types of each pair of
consecutive shapes; an unbound step and a missing one both throw `missing()` when applied. Follow-ups: US 3.4 builds
the chains (and passes `explicit: true` when the `Change` declares the chain's direction); US 9.2 reports
`missing()` as `conversion-missing` and the lossy registered steps as `conversion-lossy` (re-find the converter by the
step's types); US 4.4 custom types: the key must include the type name.

#### US 4.4 — Conversions of custom types · S · depends US 2.5, US 4.3

- A custom type registers its converters with the `Converters` registry when registered in the `TypeRegistry`.

Tests: `test_a_custom_type_converts_to_a_float_with_its_own_converter`.

Note (from US 4.1): `Converters` keys factories by `(Kind, Kind, Direction)`; every custom type is `Kind::Custom`,
so the key (and `find()`) must include the custom type's name, or two custom types overwrite each other.

**Done** (2026-11-12): `Schema\DeclaresConversions` (static `conversions(Converters): Converters`, implemented by the
class of a custom type), `TypeRegistry::converters(?Converters)` (the built-in registry, or the given one, with the
conversions of every registered type, in registration order), given to `ShapeSelector::at()` (whose default stays
the built-in registry: the selector reads no global state).
`Converters::register()` takes `Kind|string` sides (a string is a custom type's name, the one its
`TypeDefinition::custom()` declares, not the factory name); `Converters::key()` keys it `custom(money)`;
`Converters::keyOf(TypeDefinition, TypeDefinition, Direction)` is what `find()` and `ConversionChain::withStep()` use.
Fixtures: `MoneySchema` converts to and from a float (`MoneyToFloat`, `FloatToMoney`). Coverage 99.88 %, Infection
MSI 98 % (the equivalent mutant of `toEnum()` from US 4.1). Review: the selector keeps the built-in registry as
default (no global state read), impossible sides are refused, the up key is tested. Deviations: "registers its converters when registered in
the `TypeRegistry`" is a hook read by `TypeRegistry::converters()`, not a registration into a global registry
(`Converters` stays an immutable instance, no new static state); `Kind::Custom` registered without a name matches no
custom type: it throws `DefinitionException::invalidConversionSide()`, as an empty name does. Follow-ups: US 5.1 passes `TypeRegistry::converters()` (or the application's) to the selector, and the
projector / upgrader bind with the same registry; US 9.2 reads `lossless()` of custom converters for
`conversion-lossy`.

---

### E5 — Compilation and cache

Goal: the shape of a class is computed once per revision, without a subject, and can be cached in a file.


#### US 5.1 — Probe and compiler · M · depends US 3.4

- `Schema\Revisioned` contract: `static declarationKind(): DeclarationKind` (`Response` | `RequestBody` |
  `RequestQuery`) and the
  `@internal` bridge `describeRevisioned(): array<string, Descriptor>`.
- `Compilation\Probe` (Null Object subject); `Compiler::compile(class, R)`: instantiate on the probe, call the hook,
  `ShapeSelector::at()`. A source closure run during compilation is a bug: the probe throws on any access.
- The compiler receives the `Configuration` and calls `configure()` on every descriptor, recursively for composites
  (model.md D29, from US 2.2).
- Note (from US 3.4): `ShapeSelector::at()` takes the `Direction` of the declaration kind (`Response` → down, request
  → up, D27) and the `Converters` (custom types, US 4.4); the compiler passes both and adds the class to the `Shape`.

Tests: `test_compiling_a_class_runs_no_source`, `test_compiling_gives_the_shape_of_the_revision`,
`test_compiling_resolves_the_configured_date_format`.

**Done** (2026-11-12): `Schema\Revisioned` (static `declarationKinds()`, `@internal` static
`describeRevisioned(DeclarationKind, mixed $probe)`), `Schema\DeclarationKind` (`direction()`: D27),
`Compilation\Probe` (Null Object: property, method, offset, `isset`, `unset`, count, iteration and string cast all throw
`DefinitionException::subjectReadAtCompilation()`), `Compilation\Compiler` (`Configuration`, `Converters` defaulting
to `TypeRegistry::converters()`; `compile($class, $kind, $revision)`: `notRevisioned()`, `undeclaredKind()`,
`invalidDeclaration()`, configured descriptors, `ShapeSelector::at()` with the direction, converters and
configuration, `Shape::of()` naming class and kind), `Descriptor::configured()` (final: `configure()` plus the
schemas of the history, `History::configured()`; `ObjectSchema` / `ListSchema` configure their parts through it).
Fixtures without Laravel: `Declarations\OrderDeclaration`, `StoreOrderDeclaration` (body and query),
`EagerDeclaration`, `InvalidDeclaration`, `Customer`. Decision taken with the maintainer: **D48** (static
contract per declaration kind: a `FormRequest` declares body and query; the class builds itself on the probe).
Coverage 99.89 %, Infection MSI 99 % (one equivalent mutant of `ObjectSchema::parts()`, older). Review: the probe keeps its class private
(a `->class` read throws too), `configured()` configures the history of the configured copy, the no-source test
compiles a declaration whose source, `when()`, `down:` and `up:` throw (`ThrowingDeclaration`), the shape test compares
the whole shape. Deviations: the
contract is `declarationKinds()` (a list) and a static `describeRevisioned($kind, $probe)` instead of
`declarationKind()` and an instance bridge (D48); `compile()` takes the kind; `was()` schemas were never configured
(a date `was(schema:)` kept no format): `Descriptor::configured()` fixes it, `configure()` stays the override point.
Follow-ups: US 5.2 memoizes per `(class, kind, revision)`; US 8.5 / US 8.7 implement the bridge in the traits
(a resource built on the probe, a request without subject; eager values wrapped, US 8.5); US 2.6 compiles the
class of a `ResourceSchema` through the same compiler; a disallowed-calls rule could keep `describeRevisioned()` to
the compiler.

#### US 5.2 — Shapes repository · S · depends US 5.1

- `Compilation\Shapes::for(class, kind, R)` memoized (one shape per declaration kind, D48) (an instance repository keeping its shapes, not `Support\Memo`: see Done); `Shapes::flush()` (Octane, tests).

Tests: `test_a_shape_is_compiled_once_per_class_and_revision`, `test_flush_forgets_compiled_shapes`.

**Done** (2026-11-12): `Compilation\Shapes` (`final`, built with its `Compiler`; `for($class, $kind, $revision)`
keyed `class@kind@RevisionEnum::Case`, `flush()`); fixture `CountingDeclaration`. Every reference fixture at every
revision and kind equals its compilation (milestone M1). Coverage 99.89 %, Infection MSI 100 %. Deviation: an **instance** repository
keeping its shapes itself, not static methods over `Support\Memo`: a static `Shapes::for()` would need a global
compiler (new static state, DoD 8) and would serve a shape compiled with another configuration or other converters;
the Laravel layer binds one instance (US 8.1). A compilation that throws stores nothing. Review: custom types registered after a
`for()` need a `flush()` (documented), US 5.3 keys its file with the kind. Follow-ups: US 8.1 binds `Shapes` as a
singleton built from the configuration and flushes it with Octane and with the `TypeRegistry`; US 5.3 reads `CompiledShapes`
first; US 6.2 resolves a nested resource as `for($type->resource, $kind, $field->revision)` (D49).

#### US 5.3 — Compiled file and `revision:cache` · M · depends US 5.2, US 8.1

- `CompiledShapes` writes / reads a PHP file (`var_export` of the shapes, keyed `class@kind@revision`); `Shapes` reads it
  first. Commands `revision:cache` and `revision:clear` (Laravel layer), registered with
  `ServiceProvider::optimizes()`; file `bootstrap/cache/revisions.php` (`cache_path`).
- The compiled shapes depend on the configured date formats (D29): changing them requires `revision:cache` again.

Tests: `test_a_cached_shape_equals_the_compiled_one`, `test_projection_from_the_cache_runs_the_closures_of_the_instance`,
`test_artisan_optimize_builds_the_revisions_cache`, `test_optimize_clear_removes_the_revisions_cache`.

#### US 5.4 — Benchmark gate · M · depends US 6.1

- Allow facades and helpers in `bin/benchmark/*` (it boots Laravel; follow-up of US 0.2).
- `bin/benchmark.php`: project 1 000 orders at the head and at `Genesis`, with and without the compiled file; compare
  with a plain `JsonResource`; reference in `bin/benchmark/reference.json`; fail beyond the tolerance (10 %).

---

### E6 — Projection (response)

Goal: a subject is rendered in the shape of any revision.

#### US 6.1 — Projector and source reader · M · depends US 5.2

- `Projection\Projector::project(Shape, array $descriptors, mixed $subject): array<string, mixed>` running the
  pipeline of `model.md` §6.5: conditions → source → normalize → down chain → serialize.
- `SourceReader`: keys and dotted paths on arrays, `ArrayAccess`, objects (property, then `__get()`); a missing key
  reads `null`; `null` in a path stops it. `SourceReader::peek()`: the declared key without side effect, the
  `$value` of a source closure.
- Only visible sources evaluated; a renamed field reads its head key; `null` skips normalization and conversions;
  every closure called with `($value, $subject)`; presence conditions before the source, `when()` after the
  normalization.
- Spike M0 findings to address: F6, F11 (§3.1).

Tests: `test_only_visible_sources_are_evaluated`, `test_a_field_is_written_under_its_name_at_the_revision`,
`test_a_renamed_field_reads_its_head_key`, `test_a_dotted_source_stops_at_null`,
`test_a_source_closure_receives_the_peeked_value_and_the_subject`, `test_peek_never_calls_a_magic_getter`,
`test_when_receives_the_normalized_head_value`, `test_conversions_receive_normalized_values`,
`test_a_down_closure_receives_the_subject`, `test_null_is_rendered_null_in_every_shape`,
`test_a_failing_condition_omits_the_field`, `test_projecting_twice_gives_the_same_result`.

Follow-up (from US 2.4): evaluate `Descriptor::presences()` before the source (a `Presence` with a `null` path checks
the field's own key, D37) and `conditions()` after normalization, all of them required; test
`test_a_presence_condition_runs_before_the_source`.

**Done** (2026-11-12): `Projection\Projector` (`final readonly`: `Configuration`, `Converters` defaulting to
`TypeRegistry::converters()`, `SourceReader`; `project($shape, $descriptors, $subject)`: presences (D37), source,
normalize, `when()` kept on `true` as its PHPDoc says, chain bound to the `down:` of the fresh descriptors, serialized
by the configured descriptor in force at the revision: spike **F6**, the shape says which), `Projection\SourceReader`
(`read()`, `has()`, `peek()`; `__get()` / `__isset()` called directly, never `isset()` before a read: spike **F11**),
`Exceptions\ValueException` (`refused()`, `unconvertible()`: class, field, revision and types, never the value: the
`ConversionException` is not chained, its message holds the value),
`DefinitionException::missingDescriptor()`; fixture `MagicSubject` (a strict-mode magic subject). Coverage 99.90 %,
Infection MSI 100 % on the new classes. Deviations: until US 6.4, every value error throws `ValueException` (the
strict behaviour; a `ConversionException` of the chain, a missing step included, becomes `unconvertible()`), and a
non-nullable `null` renders `null`; composites are projected as their normalized container (an object without source
reads the parent's subject), their children and items by US 6.2; the class in errors is `Shape::$class`, `object` for a
shape without class. Follow-ups: US 6.4 puts the `ValueErrorHandler` between the projector and `ValueException`;
US 8.5 decorates the `SourceReader` for Eloquent (it is `final`: an interface then); US 5.4 measures the per-field
`configured()` / `bind()` and `TypeRegistry::converters()` per `project()`. Review: no value through a chained
exception, `has()` checks every segment before reading it (no `__get()` in a `whenHas()`), one order of `has()` and
`read()`, a shape pointing at a `was()` without schema throws `missingDescriptor()` instead of serializing with the
head.

#### US 6.2 — Nested projection · M · depends US 6.1

- Objects and lists projected recursively with their children's histories and subjects (`model.md` §6.1); `null`
  objects stay `null`; `ResourceSchema` projects the other class at the same revision. Children are projected before
  the composite's own `when()` and down conversions, which receive the projected children (D34); an object without
  source reads its children on the parent's subject (`Source::parent()`).

Tests: `test_a_child_renamed_in_an_object_is_projected_under_its_old_name`,
`test_an_object_with_a_source_reads_its_children_on_the_source_value`,
`test_an_object_without_source_reads_its_children_on_the_parent_subject`,
`test_a_list_of_objects_is_projected_item_by_item`, `test_a_nested_resource_is_projected_at_the_same_revision`.

#### US 6.3 — Invariant test · S · depends US 6.2

- For every fixture class and every revision: projected keys (recursively) = `Shape` names. Extended by US 10.1
  (projected document validates against the exported schema).
- Spike M0 findings to address: F12 (round trip) (§3.1).

Tests: `test_projection_and_shape_agree_for_every_fixture_at_every_revision`.

#### US 6.4 — Value errors · S · depends US 6.1

*As an application developer, I want wrong data in my models to fail loudly in development and not to break
production, so that type drift is caught without outages.*

- `Projection\ValueErrorHandler` (Strategy): `StrictValues` throws `ValueException` (class, field, revision, the value's
  type, never the value itself); `LenientValues` renders `InvalidValue::cast`, the PHP cast computed by the type
  (`(int)`, `(string)`…, the raw value for an unknown enum; `null` when no cast makes sense) and passes the error to a
  reporter (`Closure(ValueException): void`).
- Chosen from `Configuration::strictValues`; a non-nullable field resolving `null` is a value error.
- Spike M0 findings to address: F11 (§3.1).

Tests: `test_strict_values_throw_on_a_refused_value`, `test_lenient_values_cast_and_report`,
`test_null_on_a_non_nullable_field_is_a_value_error`, `test_the_exception_does_not_contain_the_value`.

---

### E7 — Upgrade (request)

Goal: a validated input of revision `R` becomes the input of the head.

#### US 7.1 — Upgrader · M · depends US 5.2

- `Upgrade\Input` (present / absent / explicit null); `Upgrader::upgrade(Shape $from, array $input, ?UpgradesInput
  $hook)`: steps from `R` to the head; per step renames + `up` chains, hook, defaults of `since(next)` fields; never a
  default over an explicit `null`; absent keys stay absent.
- Spike M0 findings to address: F1, F3, F5, F6, F9, F10, F12 (Q5, Q6) (§3.1).

Tests: `test_a_genesis_input_is_upgraded_to_the_head`, `test_a_renamed_key_is_moved_to_its_head_name`,
`test_a_default_fills_an_absent_key_added_later`, `test_a_default_never_replaces_an_explicit_null`,
`test_an_absent_key_is_not_converted`.

#### US 7.2 — The `upgrade()` hook · S · depends US 7.1

- `Upgrade\UpgradesInput::upgrade(Revision $to, array $input): array`, called once per step, after the `was()` of the
  step and before its defaults.

Tests: `test_the_hook_is_called_once_per_step_with_its_target`, `test_the_hook_sees_the_names_of_its_step`.

#### US 7.3 — Nested upgrade · M · depends US 7.1

- Objects and lists upgraded recursively; renames and conversions of children.

Tests: `test_a_child_renamed_in_an_object_is_upgraded`, `test_each_item_of_a_list_is_upgraded`.

---

### E8 — Laravel runtime

Goal: a Laravel application resolves the revision of each request, renders resources and validates requests in it.

#### US 8.1 — Service provider and configuration · S · depends US 1.3

- Add `config` to the PHPStan `paths` (follow-up of US 0.2).
- `config/revisions.php` documented key by key (`model.md` §8); `Configuration` built from it (validated: `enum`
  implements `Revision`, resolvers exist, non-empty date formats); `RevisionScope` provider = `scoped` binding;
  publishable config.
- Outside a request, the scope's base is the configured `default` when it is a fixed revision (`latest`, `oldest`, a
  backed value), the head otherwise (D31).

Tests: `test_an_enum_not_implementing_revision_is_refused`, `test_the_scope_is_reset_between_two_requests`,
`test_outside_a_request_current_is_the_configured_fixed_default`.

#### US 8.2 — Resolvers · M · depends US 8.1

- `RevisionResolver` contract; `HeaderResolver`, `QueryResolver`, `PathResolver`; chain built from the configuration,
  first non-null wins; `default`: `latest`, `oldest`, a backed value, or a resolver class.

Tests: `test_the_first_resolver_returning_a_revision_wins`, `test_the_default_applies_when_no_resolver_answers`,
`test_a_custom_resolver_pins_the_revision_of_an_account`.

#### US 8.3 — `ResolveRevision` middleware · M · depends US 8.2

- Unknown value → `UnknownRevisionException` rendered **400** (JSON body naming the accepted values); stores the
  revision in the scope; response header; `Vary` in header / query mode; `Deprecation` / `Sunset` from `meta()`
  (midnight of the day in the application timezone, D30).

Tests: `test_an_unknown_revision_is_answered_with_400`, `test_the_response_carries_the_revision_header`,
`test_a_deprecated_revision_adds_deprecation_and_sunset_headers`, `test_header_mode_adds_vary`.

#### US 8.4 — Path mode · M · depends US 8.3

- `Route::revisioned(Closure)` (prefix `{revision}` constrained to the enum values); the middleware forgets the
  parameter and sets `URL::defaults(['revision' => …])`.

Tests: `test_a_revisioned_route_resolves_the_revision_from_the_path`,
`test_the_controller_does_not_receive_the_revision_parameter`, `test_generated_urls_keep_the_revision`.

#### US 8.5 — `RevisionedResource` · M · depends US 6.2, US 8.1

- Trait for `JsonResource`: `toArray()` returns descriptors; `resolve()` projects them on `$this->resource` at
  `current()` (`model.md` §6.6); `with()`, `additional()`, wrapping, collections and pagination work as with a plain
  `JsonResource`; implements the `Revisioned` bridge for the compiler.
- Plain values rendered as is (`eager-value`); native `when()` / `whenLoaded()` / `mergeWhen()` honoured at run time
  (`native-condition`); `->whenLoaded('relation')` on descriptors; `Arrayable` normalization of `any` values; lenient
  value errors reported with `report()`.

Tests (Feature, reference scenario): `test_order_resource_renders_each_revision_as_in_the_reference_table`,
`test_a_resource_collection_is_projected_item_by_item`, `test_closures_in_to_array_reach_the_model_three_ways`,
`test_when_loaded_omits_a_relation_not_loaded`, `test_an_eager_value_is_rendered_as_is`.

Follow-up (from US 2.4): an eager value is rendered as is, `null` included, while `RuleSchema::normalize()` refuses
`null` like every descriptor: an eager value is typed as a nullable `any`, not a `RuleSchema` reused as is.

#### US 8.6 — Validation rules of a shape · L · depends US 5.2, US 8.1

- `Laravel\Validation\RuleCompiler` + one `RuleMapper` per `Kind`: rules under the **names of the revision**, with the
  converters' `extraRules()` (`numeric`), the enum rule, nested `*.` keys, the user `rules()`, `required` / `nullable`
  / `sometimes`.

Tests: `test_rules_use_the_names_of_the_client_revision`, `test_a_string_to_int_change_adds_numeric_to_the_old_rules`,
`test_nested_rules_use_dotted_keys`, `test_user_rules_are_kept_as_given`.

#### US 8.7 — `RevisionedRequest` · L · depends US 7.3, US 8.6

- Trait for `FormRequest`: `body()`, `query()`; `rules()` = rules of `Shape(current())`; after validation, the
  upgrader; `validated()` / `safe()` return the head input; the `upgrade()` hook of the request is the
  `UpgradesInput`; a `ConversionException` becomes a validation error on the client's key.
- Decide Q18 first (§6): how a user `up:` closure rejects an input (a public `ConversionException` constructor or an
  exception contract caught by the layer).

Tests (Feature): `test_a_genesis_client_is_validated_with_its_own_names`,
`test_the_controller_receives_the_head_input`, `test_an_invalid_enum_value_is_a_validation_error_on_the_old_key`,
`test_query_parameters_are_validated_and_upgraded`.

#### US 8.8 — Outside HTTP · S · depends US 8.1

- `Laravel\Queue\CapturesRevision`: the revision at dispatch is stored on the job and restored with `using()` when it
  runs.

Tests: `test_a_job_runs_in_the_revision_of_its_dispatch`.

---

### E9 — Check and lock

Goal: CI catches every inconsistent declaration and every change to a published revision.

#### US 9.1 — Diagnostics and checker · S · depends US 5.2

- `Support\Diagnostic`, `Diagnostics`, `Severity`; `Checking\Check`, `Checker`, `CheckContext`; `ClassFinder`
  contract.

Tests: `test_a_diagnostic_names_its_class_field_and_revision`, `test_the_checker_runs_every_check_on_every_class`.

#### US 9.2 — Check rules · M · depends US 9.1

- One class per code of `model.md` §9 except the lock ones.
- Spike M0 findings to address: F8, F10 (§3.1).

Tests: one passing and one failing declaration per code, e.g.
`test_a_required_field_added_later_without_default_is_reported`, `test_a_lossy_conversion_without_cast_is_reported`,
`test_a_rule_keyword_overriding_the_type_is_reported` (`schema-override`, D36: compare the exported keywords of the
type with `TypeDefinition::$jsonSchema`). The repeated `since()` / `until()` calls of model.md §4.4 rule 5 are read
from `Descriptor::repeatedBounds()` (US 3.1). Declaration errors are caught per class and reported under their code, else
`definition-error` (D39): `test_a_declaration_error_is_reported_and_the_check_goes_on`.

#### US 9.3 — `revision:check` and the PHPUnit assertion · S · depends US 9.2, US 8.1

- `Laravel\Discovery\PathClassFinder` (`Revisioned` classes under `discovery.paths`, plus `discovery.classes`);
  command `revision:check` (exit code ≠ 0 on error, table output, `--format=json`, `--list` prints the discovered
  classes); trait `Testing\InteractsWithRevisions` (D28) with `assertRevisionsAreConsistent()`.

Tests: `test_the_check_command_fails_on_an_error`, `test_the_assertion_fails_with_the_diagnostics`,
`test_explicit_classes_outside_the_paths_are_checked`.

#### US 9.4 — Lock · M · depends US 10.1, US 9.3

- `Checking\Lock` writes / compares normalized snapshots (`model.md` §14 D11) and the recorded `order()`; command
  `revision:lock` (all but the head, `--revision=`); checks `lock-mismatch`, `lock-order-changed`.

Tests: `test_a_field_leaking_into_a_locked_revision_fails_the_check`, `test_moving_a_locked_revision_fails_the_check`,
`test_the_head_is_never_locked_by_default`.

---

### E10 — Export and tooling

#### US 10.1 — JSON Schema export · M · depends US 5.2

- `Export\SchemaVisitor`, `JsonSchemaExporter` (2020-12): types, constraints, enum values, nullable, required,
  conditional = optional, descriptions, examples, `deprecated`, revision `meta()` in `$comment` / `description`;
  `DescribesSchema` honoured. Command `revision:export {--revision=} {--class=}`.
- Extends the invariant test (US 6.3): every projected fixture validates against its revision's schema (opis).
- Object children are exported with their own required / nullable / history (the field definitions of US 3.4),
  not only their `TypeDefinition` (from US 2.3).
- `TypeDefinition::$jsonSchema` (the keywords of the `DescribesSchema` rules, US 2.4) is merged into the exported
  type, **after** the typed keywords: a rule keyword wins even when it contradicts them (D36).

Tests: `test_each_revision_exports_its_own_schema`, `test_a_projected_document_matches_the_schema_of_its_revision`.

#### US 10.2 — `$ref` components · M · depends US 10.1

- `Export\Components`: an object or custom type used in several classes is exported once and referenced; its
  history applies in every reference.

Tests: `test_a_shared_object_is_exported_as_a_component`.

#### US 10.3 — Changelog · M · depends US 10.1

- `Export\Changelog`: diff of consecutive shapes per class (added, removed, renamed from the `History`, type changed),
  Markdown with `meta()`; command `revision:changelog {--from=} {--to=}`.

Tests: `test_the_changelog_lists_a_rename_as_a_rename`, `test_the_changelog_uses_the_revision_descriptions`.

#### US 10.4 — Testing helpers · M · depends US 10.1, US 8.3

- `Testing` (D28): `TestResponse` macro `assertMatchesRevisionSchema(R)`; `withRevision(R)` (header or path according
  to the configuration) and the contract-test helper (head fixture rendered at `R`, validated) on the trait
  `InteractsWithRevisions`.

Tests: `test_with_revision_sends_the_configured_header`, `test_assert_matches_revision_schema_fails_on_an_extra_field`.

#### US 10.5 — `revision:prune` · S · depends US 9.2

- Lists the declarations made dead by the oldest revision (`was(oldest)`, `until(oldest)`, `since(oldest)`), with
  file and line when reflection can tell.

Tests: `test_prune_lists_the_declarations_serving_only_the_oldest_revision`.

---

### E11 — Documentation and release

#### US 11.1 — README and guide · S · depends M3

- README: install, the enum, a resource, a request, resolution, check / lock in CI, retiring a revision, outside HTTP.

#### US 11.2 — Agent skill · M · depends US 11.1

- `skills/laravel-api-revisions/` (`SKILL.md` with the rules that prevent most mistakes, `reference.md`, `recipes.md`
  with tested examples); `tests/Unit/SkillTest` checks that the reference names every public entry point.
- Spike M0 findings to address: F13 (§3.1).

#### US 11.3 — Release 1.0.0 · S · depends all

- `CHANGELOG.md` `[1.0.0]`; benchmark reference; the tag is the maintainer's.

---

## 3. Order and milestones

```
E0 ── 0.1 ─ 0.2 ─ 0.3 ─ 0.4
E1 ────────────── 1.1 ─┬─ 1.2
                       └─ 1.3 ──────────────────────────────── 8.1 ─ 8.2 ─ 8.3 ─ 8.4
E2 ────────────── 2.1 ─ 2.2 ─ 2.3 ─ 2.4 ─ 2.5                    │
E4 ────────────── 4.1 ─ 4.2 ─ 4.3 ─ 4.4                           ├─ 8.5 (after 6.2)
E3 ──────────────────── 3.1 ─ 3.2 ─ 3.3 ─ 3.4 ─ 3.5               ├─ 8.6 ─ 8.7 (after 7.3)
E5 ─────────────────────────────────────── 5.1 ─ 5.2 ─ 5.3 ─ 5.4  └─ 8.8
E6 ───────────────────────────────────────────── 6.1 ─ 6.2 ─ 6.3
E7 ───────────────────────────────────────────── 7.1 ─ 7.2 ─ 7.3
E9 ───────────────────────────────────────────── 9.1 ─ 9.2 ─ 9.3 ─ 9.4 (after 10.1)
E10 ──────────────────────────────────────────── 10.1 ─ 10.2 ─ 10.3 ─ 10.4 ─ 10.5
E11 ── last
```

| Milestone | Content | Exit criterion |
| --- | --- | --- |
| **M0 — Spike** (throwaway branch `spike/core`, 2 days) | minimal `RevisionOrder`, scalar descriptors, `since` / `until` / `was`, `ShapeSelector`, `Projector`, `Upgrader` on the reference scenario | the 4 rows of `model.md` §4.2 rendered and the `Genesis` input upgraded to the head; chained inheritance confirmed; a shape serialized then re-bound to fresh closures; deleting `Genesis` makes PHPStan report every `was(Genesis)` |
| **M1 — Core** | E0, E1, E2, E3, E4, US 5.1–5.2 | `Shapes::for()` correct for every fixture at every revision |
| **M2 — Runtime** | E6, E7, E8 | Feature scenario green at every revision, both directions |
| **M3 — Safety** | E9, US 10.1, US 5.3, US 5.4 | every diagnostic code covered; lock in CI; benchmark gate |
| **M4 — Release** | US 10.2–10.5, E11 | 1.0.0 ready to tag; X1 and X2 unblocked |

Estimate (S = 1 d, M = 2.5 d, L = 4.5 d): E0 4 · E1 4.5 · E2 9.5 · E3 10 · E4 7 · E5 8.5 · E6 6 · E7 6 · E8 21 ·
E9 7 · E10 11 · E11 4.5, plus US 2.6 and 6.4 (2) → about **102 days** of nominal work.


### 3.1 Spike M0 — results

Branch `spike/core` (commit `df0a523`, throwaway, never merged): `RevisionOrder`, scalar and enum descriptors,
`since` / `until` / `was` (chained), `ShapeSelector`, `Projector`, `Upgrader`, a static table of automatic conversions,
on the reference scenario without Laravel. 14 tests, PHPStan max green; reviewed by `architecture-reviewer`.

| Exit criterion | Result |
| --- | --- |
| The 4 rows of `model.md` §4.2 | ✅ rendered exactly, `Shape::names()` = projected keys at every revision |
| `Genesis` input upgraded to the head | ✅ `{state: 'paid', total: 12.5, name: 'Ada Lovelace'}` → `{status, total: 1250, first_name, last_name, priority: 0}` |
| Chained inheritance | ✅ `was(Winter25, name: 'state')->was(Genesis, schema: string())` renders `state` at `Genesis` (json-api 2.1 would render `status`) |
| Shape serialized, re-bound to fresh closures | ✅ `serialize()` / `unserialize()`, the `down:` closure of the fresh descriptors runs; compiling runs no source |
| Retiring `Genesis` | ✅ deleting the case: PHPStan reports exactly the two `was(Genesis)` (`classConstant.notFound`), nothing else |

Findings carried into the stories (the stories cite them):

| # | Finding | Stories |
| --- | --- | --- |
| F1 | Renames applied in place lose data on a rename chain in one step (`y→z`, `x→y`): read every old name from a snapshot of the step, unset all, then write | 7.1 |
| F2 | Automatic conversions must be keyed by **direction**; `string → int/float` down casts silently (`'abc'` → `0`), `int → bool` is lossy, non-scalars become `''` | 4.1 (Q4) |
| F3 | The upgrade feeds raw input to `up:` closures and converters (`'5'` to `fn (int $n)` → `TypeError`): down receives normalized values, up does not; `string/int → enum` needs the enum class | 4.3, 7.1 (Q5) |
| F4 | Revisions stored as strings break int-backed enums (`::from((string) 1)`): store the case (or `int\|string`) and compare through `RevisionOrder` | 1.1, 3.4 |
| F5 | Defaults and drops are planned only for fields visible at `R`: a `since(W)->until(S)` field with a default leaks into the head; a default of a field renamed later is written under the head key | 3.4, 7.1 (Q6) |
| F6 | Shape decisions recomputed outside `ShapeSelector`: the projector finds the serializer of `R`, the upgrader rebuilds steps from strings, both resolve converters at run time. The `Shape` must carry the type in force at `R`, an ordered list of `UpgradeStep`s, and converter **keys** (an enum, not `'auto'` / `'closure'` strings) | 3.4, 4.3, 6.1, 7.1 |
| F7 | Plans compare `Kind` only: an enum class / backing type / date format / children change is planned as `identity`. Plan on `TypeDefinition` | 4.3 |
| F8 | Plans hold both directions: responses never declare `up:`, requests never `down:`, so shapes are full of `missing`. Plan only the direction of the declaration kind | 4.3, 9.2 (Q8) |
| F9 | What an `up:` closure receives as `$subject` is order-dependent: define it as a snapshot of the input at the start of the step, in the shape of `X` | 7.1 (Q5) |
| F10 | Model gaps: `until(X)` fields dropped after the hook of step `X → next` (not written in §7.1); a group `until()` widens a member's earlier `until()` silently; a `was()` newer than the field's `until` is not a rule; a `was(schema:)` carrying its own history or default is not refused | 3.2 (Q7), 3.3, 7.1, 9.2 |
| F11 | Value errors: `normalize()` must report through the `ValueErrorHandler` with class, field, revision; a non-nullable `null` is not reported; `$subject->{$key} ?? null` calls `__isset()` and hides Eloquent strict-mode errors §6.2 wants visible | 6.1, 6.4 |
| F12 | Tests to add: round trip (down then up gives the head value back: 1250 → 12.5 → 1250), upgrades from every revision, an `until` field the hook does not consume, `mixedRevisions` / `invalidOrder` | 1.1, 6.3, 7.1 |
| F13 | Author trap: a `down:` closure receives the PHP value of the newer shape (`OrderStatus $s`, not `string`). Show it in the examples and the skill | 11.2 |

---

## 4. External epics (other repositories)

Recorded for the dependencies only; their stories are written and delivered in their own repository.

| Epic | Repository | Content | Needs |
| --- | --- | --- | --- |
| **X1** — JSON:API on revisions | `laravel-json-api` 3.0 | descriptors replaced by `Schema`; relationships with history (`include`, `included`); type renames; attribute ↔ relationship; `fields` / `include` / `sort` / `filter` per revision; `Fields` / `Includes` rules from the client's shape; adapter for 2.1 declarations (1:1, same bounds) | M2 (Projector, Upgrader, `Shape` public) |
| **X2** — OpenAPI per revision | `laravel-openapi-generator` | reads the JSON Schema export of each revision; one document per revision (`--revision=`); changelog in `info.description` | US 10.1, US 10.3 |

---

## 5. Later (not v1)

| Item | Note |
| --- | --- |
| PHPStan extension | source closure return type vs declared type (D14) |
| TypeScript types per revision | from the JSON Schema export |
| `oneOf` polymorphism | discriminated fields |
| Laravel 12 `JsonApiResource` adapter | after X1 |

---

## 6. Questions

The proposed decisions D6–D15 of `model.md` §14 are applied by default. Open questions found while implementing are
added here with the story that raised them, then moved to `model.md` §14 once decided.

| # | Question | Raised by | Status |
| --- | --- | --- | --- |
| Q1 | Lock granularity: one file per revision, or per class and revision (smaller diffs)? | US 9.4 | **Decided** (2026-11-11): one file per revision (D11) |
| Q2 | Is a revision "published" (lockable) by `publishedAt` in `#[Meta]` rather than "all but the head"? | US 9.4 | **Decided** (2026-11-11): `#[Meta]` is informative only and optional; `revision:lock` locks every revision but the head (D21) |
| Q3 | `query()` arrays (`filter[status]`, `sort=-total`): do renames apply inside values (`sort`) or only keys? | US 8.7 | **Decided** (2026-11-11): renames apply to **keys** only; a change of the values is a revision of its own, declared with `was()` (D22) |
| Q4 | Automatic conversions per direction (F2): propose — `string → int/float` automatic **up only** (rule `integer` for int, `numeric` for float), explicit down; `int → bool` never automatic; non-scalars never converted | US 4.1 | **Decided** (2026-11-11): all scalar pairs automatic both ways, `conversion-lossy` warning (D25) |
| Q5 | Upgrade values (F3, F9): propose — after validation the input of `R` is **normalized** to the types of the shape of `R` (enum cases, ints, dates), `up:` closures and converters receive normalized values, `$subject` is the snapshot of the step in the shape of `X`; `validated()` returns the head **PHP values** (enum cases, not strings) | US 7.1 | **Decided** (2026-11-11): option B — normalize before each up conversion, `validated()` raw (D26) |
| Q6 | Defaults of fields added later (F5): propose — a default is written in the shape of its `since` revision, then travels through the later steps (renamed, converted) like sent input | US 3.4, 7.1 | **Decided** (2026-11-11): as proposed (D23) |
| Q7 | Group `until()` vs a member's own `until()` (F10): propose — mirror of `since`: the member's earlier `until` wins, a later one throws | US 3.2 | **Decided** (2026-11-11): as proposed (D24) |
| Q8 | Conversion plans per declaration kind (F8): propose — a response shape plans `down` only, a request shape `up` only; `conversion-missing` checks the planned direction | US 4.3, 9.2 | **Decided** (2026-11-11): as proposed (D27) |
| Q9 | Test helpers for applications (US 9.3 `assertRevisionsAreConsistent()`, US 10.4 `withRevision()`, `assertMatchesRevisionSchema()`): propose — one trait `Testing\\InteractsWithRevisions` for the application's `TestCase` (as Laravel's `InteractsWithDatabase`), added to §16 and to the allowed traits; `assertMatchesRevisionSchema()` as a `TestResponse` macro | US 0.3 review | **Decided** (2026-11-11): as proposed (D28) |
| Q10 | When is a `#[Meta]` day reached? Applied for now: `isDeprecated($now)` / `isSunset($now)` hold **from the day included**, the day read in the timezone of `$now`. Open for US 8.x: the instant sent in the `Deprecation` / `Sunset` headers (midnight in the application timezone, or UTC?) | US 1.2 | **Decided** (2026-11-11): D30: application timezone for the headers |
| Q11 | Outside a request (jobs, console) nothing sets the base revision: `current()` gives the head (US 1.3), while model.md §10 says "the configured default". Should the Laravel provider (US 8.1) set the base to the configured default when it is a fixed revision (`latest`, `oldest`, a value), the head otherwise? | US 1.3 | **Decided** (2026-11-11): D31: the configured `default` when it is a fixed revision, set by the Laravel provider |
| Q12 | Numeric strings (model.md §6.3 says "integral numeric string"). Applied: int accepts digits (`'-007'`, exact to the int range) and any numeric string with an integral value exact as a float (`'12.00'` from a MySQL DECIMAL, `'1e3'`, up to 2^53); int and float refuse surrounding blanks and a trailing newline; a float written as a string keeps every digit (`0.1 + 0.2` → `'0.30000000000000004'`), INF / NaN refused; an int beyond 2^53 normalized to float loses precision, as in PHP. Confirm, then record in §14 | US 2.1 | **Decided** (2026-11-11): D32 |
| Q13 | Dates and enums beyond model.md §6.3 (US 2.2). Applied: a date string is what `DateTimeImmutable` parses **without warning** (`2026-02-30` refused, never rolled over) and without surrounding blanks; relative strings (`tomorrow`, `now`) accepted; a string without offset is read in the default timezone, an int timestamp in UTC. An int-backed enum accepts what `int` accepts (`'3'`, `3.0`); a refused enum value keeps its backed value as lenient cast. Confirm, then record in §14 | US 2.2 | **Decided** (2026-11-11): D33 |
| Q14 | `DescribesSchema` (US 2.4). Applied: a rule implementing it enriches the type of **any** descriptor (`rules()` too, not only `Schema::rule()`, as model.md §3 words it); keywords merged in declaration order after those of `type()`, a later one wins; refused unless JSON-ready (scalars, arrays, nulls: `DefinitionException::invalidJsonSchema()`). Open for US 10.1: when a rule keyword contradicts the typed ones (`type`, `minimum`, `format`, `enum` against `kind`, `min`, `values`), which wins in the export, and should the check (US 9.2) report it? | US 2.4 | **Decided** (2026-11-11): D36: rule keywords apply last, in order, even over the typed ones; the check warns (`schema-override`) |
| Q15 | `whenHas()` without key checks "the field's own key". Proposed for US 6.1: the source path when the source is a key or dotted path, the head declared key otherwise (closure source, `Source::parent()`); never the name at an older revision. Confirm | US 2.4 | **Decided** (2026-11-11): D37 |
| Q16 | Check codes for declaration errors (US 3.1). `lifetime-inverted` (and `since-older-than-group` / `until-newer-than-group`, US 3.2) are thrown as `DefinitionException` when declared, so the check never sees them: should the check catch a `DefinitionException` while building a class and report it under its code (the class then fails as one diagnostic), or are these codes dropped from §9? And is `bound-repeated` (rule 5) an error or a **warning**? | US 3.1 | **Decided** (2026-11-11): `bound-repeated` is a warning (D38); the check catches declaration errors and reports them under their code (D39) |
| Q17 | A `was()` newer than the field's `until` (F10) is dead: the field no longer exists from `until->next()`, and a `was(until)` describes the last revision it exists in, so only a `was()` **newer than** the `until` is dead. Proposed: a check code `history-after-until` (error, mirror of `history-before-since`) in model.md §9 and §4.4 rule 3. Confirm | US 3.3 | **Decided** (2026-11-11): D43 |
| Q18 | Exceptions thrown by a user `up:` closure (US 4.2). Today only a `ConversionException` (raised by the library's converters) becomes a validation error on the client's key (US 8.7); any other exception from a closure bubbles up as a 500. Leaning towards letting a closure reject an input for its revision, either with a public `ConversionException::invalid($message)` or with an exception **contract** (an interface the Laravel layer catches) that an application exception may implement: compare both when US 8.7 starts | US 4.2 | Open (deferred to US 8.7) |

---

## 7. Risks

| Risk | Mitigation |
| --- | --- |
| A path computes visibility or names outside `ShapeSelector` (response, rules, export disagree) | Invariant test US 6.3 + US 10.1 on every fixture at every revision |
| Chained `was()` inheritance surprises authors used to json-api 2.1 (omitted = head) | `model.md` §13; skill rule; changelog shows the effective names |
| Closures lost or mismatched after cache (`revision:cache`) | Plans reference `was` indexes; US 5.3 test projects from the cache |
| A source runs during compilation (side effects, N+1) | The probe throws on any access (US 5.1) |
| Lossy automatic scalar casts surprise clients | `conversion-lossy` warning in the check; changelog shows the type change; `down:` / `up:` |
| Static state under Octane | Only `Memo` and the scope provider; `scoped` binding; US 8.1 test |
| Performance regression on large collections | Compiled plans; benchmark gate US 5.4 |
| A forgotten `since` leaks a field into old revisions | Lock (US 9.4) |

---

## 8. Tracking board

Statuses: `todo` → `doing` → `review` → `done` (or `blocked`, with the reason in the story). An agent sets `doing`
when it starts a story and `done` in the commit that completes it, with a **Done** note under the story.

| Story | Title | Size | Depends on | Milestone | Status |
| --- | --- | --- | --- | --- | --- |
| M0 | Spike (throwaway) | — | — | M0 | done |
| US 0.1 | Package skeleton | S | — | M1 | done |
| US 0.2 | Static analysis and style | S | 0.1 | M1 | done |
| US 0.3 | Architecture test | S | 0.2 | M1 | done |
| US 0.4 | Continuous integration | S | 0.2 | M1 | done |
| US 1.1 | Revision contract and order | M | 0.3 | M1 | done |
| US 1.2 | Revision metadata | S | 1.1 | M1 | done |
| US 1.3 | Current revision and `using()` | S | 1.1 | M1 | done |
| US 2.1 | Descriptor, source, scalars | M | 0.3 | M1 | done |
| US 2.2 | Dates and enums | S | 2.1 | M1 | done |
| US 2.3 | Lists and objects | M | 2.1 | M1 | done |
| US 2.4 | Escape hatch and conditions | S | 2.1 | M1 | done |
| US 2.5 | Custom types | M | 2.3, 4.1 | M1 | done |
| US 2.6 | Nested `Revisioned` classes | S | 2.3, 5.1 | M1 | done |
| US 2.7 | Custom types seen by PHPStan | S | 2.5 | M1 | done |
| US 3.1 | `since()` and `until()` | S | 1.1, 2.1 | M1 | done |
| US 3.2 | Groups | S | 3.1 | M1 | done |
| US 3.3 | `was()` | M | 3.1, 4.1 | M1 | done |
| US 3.4 | `ShapeSelector` | L | 3.2, 3.3, 4.3 | M1 | done |
| US 3.5 | Defaults and `Undefined` | S | 3.4 | M1 | done |
| US 4.1 | Converters and directions | M | 2.1 | M1 | done |
| US 4.2 | Explicit casts and closures | S | 4.1 | M1 | done |
| US 4.3 | Conversion chains | M | 4.2 | M1 | done |
| US 4.4 | Conversions of custom types | S | 2.5, 4.3 | M1 | done |
| US 5.1 | Probe and compiler | M | 3.4 | M1 | done |
| US 5.2 | Shapes repository | S | 5.1 | M1 | done |
| US 5.3 | Compiled file, `revision:cache` | M | 5.2, 8.1 | M3 | todo |
| US 5.4 | Benchmark gate | M | 6.1 | M3 | todo |
| US 6.1 | Projector and source reader | M | 5.2 | M2 | done |
| US 6.2 | Nested projection | M | 6.1 | M2 | todo |
| US 6.3 | Invariant test | S | 6.2 | M2 | todo |
| US 6.4 | Value errors | S | 6.1 | M2 | todo |
| US 7.1 | Upgrader | M | 5.2 | M2 | todo |
| US 7.2 | The `upgrade()` hook | S | 7.1 | M2 | todo |
| US 7.3 | Nested upgrade | M | 7.1 | M2 | todo |
| US 8.1 | Service provider and configuration | S | 1.3 | M2 | todo |
| US 8.2 | Resolvers | M | 8.1 | M2 | todo |
| US 8.3 | `ResolveRevision` middleware | M | 8.2 | M2 | todo |
| US 8.4 | Path mode | M | 8.3 | M2 | todo |
| US 8.5 | `RevisionedResource` | M | 6.2, 8.1 | M2 | todo |
| US 8.6 | Validation rules of a shape | L | 5.2, 8.1 | M2 | todo |
| US 8.7 | `RevisionedRequest` | L | 7.3, 8.6 | M2 | todo |
| US 8.8 | Outside HTTP | S | 8.1 | M2 | todo |
| US 9.1 | Diagnostics and checker | S | 5.2 | M3 | todo |
| US 9.2 | Check rules | M | 9.1 | M3 | todo |
| US 9.3 | `revision:check` and assertion | S | 9.2, 8.1 | M3 | todo |
| US 9.4 | Lock | M | 10.1, 9.3 | M3 | todo |
| US 10.1 | JSON Schema export | M | 5.2 | M3 | todo |
| US 10.2 | `$ref` components | M | 10.1 | M4 | todo |
| US 10.3 | Changelog | M | 10.1 | M4 | todo |
| US 10.4 | Testing helpers | M | 10.1, 8.3 | M4 | todo |
| US 10.5 | `revision:prune` | S | 9.2 | M4 | todo |
| US 11.1 | README and guide | S | M3 | M4 | todo |
| US 11.2 | Agent skill | M | 11.1 | M4 | todo |
| US 11.3 | Release 1.0.0 | S | all | M4 | todo |
