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
8. No static state outside `Support\Memo`, `RevisionScope::provider()` and `Schema\TypeRegistry` (D42), plus Laravel's
   registries filled at boot (the `revisioned` router macro, the queue payload hook, US 8.8).
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

- `CompiledShapes` writes / reads a PHP file (`var_export` of the serialized shapes, keyed `class@kind@revision`, D56); `Shapes` reads it
  first. Commands `revision:cache` and `revision:clear` (Laravel layer), registered with
  `ServiceProvider::optimizes()`; file `bootstrap/cache/revisions.php` (`cache_path`).
- The compiled shapes depend on the configured date formats (D29): changing them requires `revision:cache` again.

Tests: `test_a_cached_shape_equals_the_compiled_one`, `test_projection_from_the_cache_runs_the_closures_of_the_instance`,
`test_artisan_optimize_builds_the_revisions_cache`, `test_optimize_clear_removes_the_revisions_cache`.

**Done** (2026-11-12): `Compilation\CompiledShapes` (`final readonly`; `load($path)`, `write($path, $shapes)`,
`get($class, $kind, $revision)`, `count()`, `key()` shared with `Shapes`; the file returns
`class@kind@revision → serialize(Shape)` plus a `@format` stamp, written to a temporary file then renamed),
`Shapes::__construct(Compiler, ?CompiledShapes)` reading the file before compiling (`flush()` keeps it),
`Exceptions\CacheException` (`unwritable()`, `invalidFile()`, `uncompiledShape()`),
`Laravel\Discovery\PathClassFinder`, `Laravel\Console\CacheCommand` (`revision:cache`: discovered classes ×
`declarationKinds()` × `order()`, compiled with a fresh `Compiler`; a failure keeps the previous file) and
`ClearCommand` (`revision:clear`), registered in the console with `optimizes()`; the `Shapes` singleton loads
`cache_path`. Decision **D56**. Fixtures `tests/Fixtures/Discovery/*`, `GlobalDeclaration`. Coverage 99.6 %,
Infection MSI 85 % on the new classes (escaped: the temporary file name, `opcache_invalidate()`, the dedup values,
the token loop running `nextName()` on every token, all equivalent). Review: format stamp (a file of another release
is refused, not unserialized into a stale shape), a corrupted entry throws `invalidFile()` (no warning), the
`Revisioned` check of explicit classes moved into the finder (`ConfigurationException`; abstract classes and
interfaces refused too), a global `namespace { }` gives no leading backslash. Deviations: the file holds
**serialized** shapes, not a `var_export` of objects (the definition classes have private constructors and no
`__set_state()`; an array of strings stays in OPcache shared memory and only the shapes asked for are unserialized);
`PathClassFinder` is pulled forward from US 9.3 (the cache needs the discovery; the `ClassFinder` contract stays in
US 9.1); an explicit class that is not a concrete `Revisioned` class is a `ConfigurationException`; no compiled-file
scenario in the benchmark (US 5.4 follow-up dropped): the benchmark measures warm projections, where the shapes are
already memoized, so the file only changes the first `for()` of each shape per process. Follow-ups: US 9.1 makes
`PathClassFinder` implement `ClassFinder`; US 9.3 adds `--list`; US 11.1 documents `optimize` and the stale file.

#### US 5.4 — Benchmark gate · M · depends US 6.1

- Allow facades and helpers in `bin/benchmark/*` (it boots Laravel; follow-up of US 0.2).
- `bin/benchmark.php`: project 1 000 orders at the head and at `Genesis`, with and without the compiled file; compare
  with a plain `JsonResource`; reference in `bin/benchmark/reference.json`; fail beyond the tolerance (10 %).

**Done** (2026-11-12), reduced scope decided with the maintainer (the compiled file is US 5.3, `RevisionedResource`
US 8.5): `bin/benchmark.php` projects 1 000 `Order`s at `Atlas` and at `Genesis` through the core `Projector`
(descriptors built per order through the bridge, shapes from one `Shapes`), and resolves a plain `JsonResource` with
the same fields as baseline; it gates **ratios** to the baseline (scenarios interleaved in each of 9 runs, median of
the per-run time ratios; peak memory with the 1 000 outputs held), so it holds on any machine; it refuses to run under
Xdebug (`composer benchmark` sets `XDEBUG_MODE=off`), which skews the ratios; `--update` rewrites
`bin/benchmark/reference.json` and keeps its tolerance; CI job `benchmark`. No Laravel boot is needed, so no facade
is allowed in `bin/benchmark/*` (the US 0.2 follow-up is moot). First reference (PHP 8.5, no Xdebug): the projection
costs about ×32 the time of the plain resource at the head, ×30 at `Genesis`, and ×1.15 / ×1.04 its memory. Deviations:
no compiled-file variant, no `RevisionedResource`; no unit test for the script (tooling, like
`bin/coverage-check.php`), checked by hand: within 1 % over three runs, red on a lowered reference and on a reference
scenario not measured. Follow-ups: US 5.3 adds the compiled-file scenarios (dropped by US 5.3: see its Done note), US 8.5 replaces the bridge call by
`RevisionedResource`; the US 6.1 follow-up (per-field `configured()` / `bind()`, `TypeRegistry::converters()` per
`project()`) moves to US 5.5 (appended below), with the ×32; refresh the reference from CI (PHP 8.4) if its ratios
differ.

#### US 5.5 — Projection cost · M · depends US 5.4

*As an application developer, I want a revisioned resource to cost close to a plain one, so that adopting the library
does not slow my API.*

- The first benchmark puts the projection at about ×32 the time of a plain `JsonResource`. Measure, then remove the per-render
  work that depends only on the shape: `Descriptor::configured()` of the serializer per field, `ConversionChain::bind()`
  per field, `TypeRegistry::converters()` per `project()`, the shape lookups of nested resources (US 6.1 follow-up).
- Lower `bin/benchmark/reference.json` with the gain.

Tests: none new (the projection tests stay green); the benchmark gate measures the gain.

**Done** (2026-11-12): measured without Xdebug (a split of describe / project, then an Xdebug profile for the
distribution). Three changes take the projection from ×32 to about ×19 the time of the plain `JsonResource`
(1 000 orders: 48 → 26 ms; memory unchanged): `TypeRegistry::converters()` without argument is built once until the
next `register()` / `flush()` (it cost about 10 µs per `project()`, a third of the projection;
`test_the_default_converters_are_built_once_until_a_type_is_registered_or_flushed`,
`test_the_converters_extending_a_given_registry_are_never_cached`); the projector serializes through `configure()`
instead of `configured()` (which also configured the history, never read to serialize); `History::withChange()`
inserts the change at its place instead of appending and sorting. Reference lowered (head ×19.4, oldest ×18.9).
Deviations: `ConversionChain::bind()` per field and the nested shape lookups (`Shapes::for()`, 0.1 µs) stay: each
weighs under 1 % of a render. What remains is spread: about 40 % is the declaration itself (descriptors and `was()`
built per render, with their checks), the rest the per-field pipeline. Infection: 98 % MSI (two equivalent mutants:
the `(array)` cast of US 6.4, `$order <= 0` after the `=== 0` throw).

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

**Done** (2026-11-12): `Projector` projects composites recursively between `normalize()` and `when()` (D34): an
object its head children on its value (or the parent's subject), a list each element (an item, told by a flag, is the
element: its closure source gets it as `$value`), a `ResourceSchema` the `Shapes::for($class, Response,
$field->headAt)` of its class with descriptors from `describeRevisioned(Response, $value)`; `Projector` takes an
optional `Shapes` (one compiling with its configuration and converters by default); descriptors with a composite on
one side only throw `missingDescriptor()`. `ShapeSelector` gives the head's children and items at
`FieldDefinition::$headAt`. Decisions taken with the maintainer: **D50** (the bridge builds the class on its subject:
probe or nested value, amends D48), **D51** (a failing item is dropped, the list re-indexed), **D52** (the head
children are read where the head is in force, after its newest `was(schema:)`; changes the US 3.4 test, renamed
`test_the_schema_of_a_was_replaces_the_type_of_the_children_not_their_shape`). Fixtures `BoundDeclaration` (a closure
reading `$this->resource`), `CountingDeclaration` also declaring a response; the bridge parameter of every fixture is
`$subject`. Coverage 99.90 %, Infection MSI 98 % on `Projector` (one equivalent mutant: the `(array)` cast of a
normalized list; the older equivalent ones of `ShapeSelector` remain). Review: D52 first read the head children at `R`, so a child changing between
`R` and the `was()` gave keys the type did not have and up conversions a value they never had: corrected with the
maintainer to `headAt`; D50 tested on an instance closure; an item's closure source gets the element; `*` no longer a
sentinel; a scalar descriptor on a composite shape refused. Follow-ups: US 6.4 handles value errors in nested fields
with the class of the nested resource; US 7.3 upgrades the head children from `headAt` after the up conversion of a
`was(schema:)` (D52); US 8.5 implements the bridge on the resource's subject; rules and export (US 8.6, US 10.1) read
`type`, never `children`.

#### US 6.3 — Invariant test · S · depends US 6.2

- For every fixture class and every revision: projected keys (recursively) = `Shape` names. Extended by US 10.1
  (projected document validates against the exported schema).
- Spike M0 findings to address: F12 (round trip) (§3.1).

Tests: `test_projection_and_shape_agree_for_every_fixture_at_every_revision`.

**Done** (2026-11-12): `tests/Unit/Projection/ProjectionInvariantTest.php` projects every response fixture
(`OrderDeclaration` with its nested customer listing an order back, `CustomerDeclaration`, `BoundDeclaration`,
`CountingDeclaration`) at every revision through `Shapes` and the `Projector`, and compares the keys with the shape
names recursively: children, items, nested resources (their shape at `headAt`); a `null` only on a nullable field or
item. The response fixtures are found by scanning `tests/Fixtures/Declarations/`; the deliberately broken ones are
listed as excluded (`test_every_response_fixture_is_projected_or_excluded`). Fixture `Order` (the subject of the
reference scenario, with `fullName()`). The invariant found a fixture bug: `OrderDeclaration`'s `placed_at` declared
`was(Winter25, schema: date())` without `down:`, and no automatic date-time → date conversion exists
(`conversion-missing`): a `down:` was added (`setTime(0, 0)`); its `name` closure now reads an `Order`. No production code changed.
Deviations: the round trip of F12 needs the upgrader: it stays with US 7.1 (listed there); a composite under a
`was(schema:)` is compared by US 10.1 (its type, not its head children); the test lives in `tests/Unit/Projection/`
(pure core, no Testbench needed; recorded in architecture.md §17). Review: fixtures scanned, nulls checked against
`nullable`, F12 row of §3.1 updated, the `down:` of `placed_at` drops the time. Follow-up: US 10.1 validates each projection against the exported JSON Schema.

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

**Done** (2026-11-12): `Projection\ValueErrorHandler` (`handle($error, $fallback)`), `StrictValues`, `LenientValues`
(optional reporter `Closure(ValueException): void`), `Configuration::$strictValues` (`true` in the core),
`ValueException::nullNotAllowed()` and its `class` / `field` / `revision`; the `Projector` takes a handler or builds
it from the configuration. Spike **F11**: errors carry class, field and revision; a non-nullable `null` is reported
(also a `null` given by a `down:`; once: not after a failed conversion already reported). Decisions taken with the
maintainer: **D53** (lenient: the cast of a refused value rendered as it is, without `when()`, chain nor serializer,
so a typed closure never sees an unknown enum value; a failed conversion and a non-nullable `null` render `null`; the
core is strict by default; D19 annotated). Coverage 99.90 %, Infection MSI 99 % on the projection (the known equivalent `(array)` cast).
Deviations: the reporter is called on every error; "once per class and field" is the Laravel reporter's job (US 8.1),
which reads `ValueException::$class` / `$field`; a `null` on a field whose `when()` omits it is never reported (a
refused value is, before `when()`); the stack trace of a `ValueException` holds call arguments unless
`zend.exception_ignore_args` is on (documented). Review: refused values no longer reach closures (D53 refined with the
maintainer), `null` from a `down:` detected, PHPDoc, F11 row and D19 updated. Follow-ups: US 8.1 builds
`LenientValues` with `report()` deduplicated and passes `app.debug`.

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

**Done** (2026-11-12): `Upgrade\Upgrader` (`final readonly`, `Configuration` + optional `Converters`) and the hook
contract `Upgrade\UpgradesInput`; fixture `OrderInputDeclaration` (the body of model.md §7 with its hook). The steps
are read from the up `Shape` (visible and `added` fields, `FieldDefinition::$versions` with the new
`FieldVersion::$schemaChange`, the new `FieldDefinition::nameAt()`, `ConversionChain::stepOf()`), nothing recomputed
(F6); a down shape throws `DefinitionException::notAnUpShape()`. Findings: F1 (snapshot, removals before writes:
`test_a_rename_chain_in_one_step_loses_nothing`), F3 / F9 (normalized value and snapshot of the step:
`test_an_up_closure_receives_the_value_normalized_to_its_step_and_the_snapshot_of_the_step`), F5 (defaults at their
`since`, travelling through later renames and conversions, never past an `until`:
`test_a_default_travels_through_the_later_renames_and_conversions`,
`test_a_default_of_a_field_retired_before_the_head_never_reaches_it`), F10 (`until(X)` dropped after the hook:
`test_a_field_retired_is_dropped_after_the_hook_of_its_last_step`), F12 (round trip down then up at every revision,
the same order sent at every revision). Details recorded as D54 (head order, unknown keys kept, only converted or
defaulted values serialized, a refused value throws, a retired field never takes the name of a newer one, what the
snapshot and the hook hold). Review: a retired field reusing the name of a newer field lost its value (fixed:
`test_a_field_renamed_onto_the_name_of_a_retired_field_keeps_its_value`,
`test_a_default_reusing_the_name_of_a_retired_field_is_written`); the descriptor in force and the names at a revision
moved to the shape. Deviations: `upgrade()` also takes the head descriptors (like
`Projector::project()`: the `up:` closures and the normalizing descriptor come from the instance); no `Input` class (an
array and `array_key_exists()` tell absent from `null`) and no `UpgradeStep` class (the steps are the `Shape`'s); the
hook is called already (US 7.2 adds its tests). Infection 98 % (one equivalent `break`). Follow-ups: nested objects and
lists stay as values (US 7.3, with the head children from `headAt`).

#### US 7.2 — The `upgrade()` hook · S · depends US 7.1

- `Upgrade\UpgradesInput::upgrade(Revision $to, array $input): array`, called once per step, after the `was()` of the
  step and before its defaults.

Tests: `test_the_hook_is_called_once_per_step_with_its_target`, `test_the_hook_sees_the_names_of_its_step`.

**Done** (2026-11-12): the hook contract `Upgrade\UpgradesInput` and its call shipped with US 7.1 (its order in a step
is tested there: `test_the_hook_runs_after_the_renames_and_before_the_defaults_of_its_step`,
`test_a_field_retired_is_dropped_after_the_hook_of_its_last_step`); this story adds the two tests it lists in
`UpgraderTest`, and `test_the_hook_sees_the_values_converted_by_its_step` (the hook runs after the conversions of the
step too, review). Deviations: the tests were green when written, the behaviour having been implemented with its first
tests in US 7.1; `test_the_hook_sees_the_names_of_its_step` is the US 7.1 test
`test_each_step_renames_to_the_names_of_its_newer_revision`, renamed.
No code change; a request's `protected upgrade()` reaches the interface through the trait (US 8.7).

#### US 7.3 — Nested upgrade · M · depends US 7.1

- Objects and lists upgraded recursively; renames and conversions of children.

Tests: `test_a_child_renamed_in_an_object_is_upgraded`, `test_each_item_of_a_list_is_upgraded`.

**Done** (2026-11-12): the `Upgrader` steps every level in one pass (`level()` / `value()`): an object's children, each
item of a list, a nested resource in the shape of its class for the request's kind at `headAt` (asked: upgraded, no
hook); a composite converted at a step is converted whole and continues from the head's shape after its `was()`
(D52); the converted and defaulted values are tracked by their path of head keys and serialized at the end (asked:
serialization stays at the end, D54); `Upgrade\UpgradeRun` (`@internal`) carries the converters and the kind down the
levels; a composite or resource on one side only throws `missingDescriptor()`. Decisions recorded as D55 (D50 amended:
the upgrader builds nested resources too). Review: a composite converted whole after its children, or whose `up:` or
default holds typed children, and a list the hook reindexes, came out typed (fixed: composites serialized deep, lists
tracked whole; `test_a_composite_converted_whole_after_its_children_is_serialized_back_entirely`,
`test_the_typed_children_an_up_closure_builds_are_serialized_back`, `test_a_list_the_hook_reindexes_is_serialized_back_whole`,
`test_a_composite_default_is_serialized_back`); a resource on one side only was not refused (fixed). Fixture
`ContactDeclaration` (a request resource converting a child). Tests beyond the story's: lists of objects, children and
items serialized back, children added (default) and retired, a composite converted by its `up:`, nested resources
(own shape, the request's kind, the `Shapes` given, children serialized), a value the hook replaces. Every level now
comes out in the order its fields are written (two US 7.1 / 7.2 tests updated: the keys of a step are those of the
shape, renamed or not). Infection 95 % (equivalent mutants: the narrowing `&& instanceof` after the composite guard, and
marking a list never converted, which serializing leaves unchanged since every `serialize()` passes raw values). Follow-ups: a nested resource's descriptors are built
on each step's value (one `describeRevisioned()` per step), a cost US 5.5's benchmark does not measure yet; nested
levels are not put back in the head order.

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

**Done** (2026-11-12): `config/revisions.php` (documented key by key, `config` in the PHPStan paths), validated by
`Laravel\RevisionsConfig::fromArray()` into the core `Configuration` (`MissingDefault` mapped, formats non-empty, the
enum a backed enum implementing `Revision`, `default` resolved to a revision or kept as a class) with
`Exceptions\ConfigurationException`; `RevisionsServiceProvider` merges and publishes the file and binds `RevisionsConfig`,
`Configuration`, `Shapes` (singletons), `RevisionScope` and `ValueErrorHandler` (`scoped`), and sets the scope provider
to the container. Follow-ups of earlier stories done here: `Shapes` one instance built from the configuration (US 5.2),
`LenientValues` reporting once per class and field (US 6.4), `missing_default` mapped (US 3.5). Deviations:
`strict_values` defaults to `null` (follows `app.debug`) instead of `env('APP_DEBUG')`, which Larastan refuses outside
an application's config (model.md §8 updated); the resolvers are kept as an array, checked by US 8.2 (their classes and
interface do not exist yet), so `resolvers` defaults to `[]` until then; `Shapes` is not flushed with Octane: it
depends on the code only, and custom types are registered at boot, before any compilation; the config is read on first
use, so an application without `enum` boots, and under Octane a singleton first built during a request lives in that
request's sandbox: an Octane application adds `RevisionsConfig` and `Shapes` to `octane.warm` (documented, README in
US 11.1). Review: the scope provider read the application of the boot, so under Octane the sandbox's scope was never
seen (fixed: it reads `Container::getInstance()` at each call and is set in `register()`;
`test_the_scope_is_the_one_of_the_current_container_as_under_octane`); `response_header` must be an HTTP token; the
keywords `latest` / `oldest` win over a case backed by them. Follow-up: the resolver checks moved to US 8.2 (bullet
and test added). Infection 100 % on the new classes.

#### US 8.2 — Resolvers · M · depends US 8.1

- `RevisionResolver` contract; `HeaderResolver`, `QueryResolver`, `PathResolver`; chain built from the configuration,
  first non-null wins; `default`: `latest`, `oldest`, a backed value, or a resolver class.
- Follow-up of US 8.1: `RevisionsConfig` checks that every class of `resolvers` and a class `default` exist and
  implement `RevisionResolver` (`ConfigurationException`); the default `resolvers` becomes the header resolver.

Tests: `test_a_resolver_not_implementing_the_contract_is_refused`, `test_the_first_resolver_returning_a_revision_wins`, `test_the_default_applies_when_no_resolver_answers`,
`test_a_custom_resolver_pins_the_revision_of_an_account`.

**Done** (2026-11-12): `Laravel\Http\RevisionResolver` (`resolve(Request): ?Revision`), `Laravel\Http\Resolvers\`
`HeaderResolver` (`name`: `Api-Revision`), `QueryResolver` (`name`: `revision`, never the body), `PathResolver`
(`parameter`: `revision`), `RevisionValue` (`@internal` parsing shared by the three), `ResolverChain` (the enum, the
resolvers, the default: the first revision wins, else the fixed default, else the default resolver, else the head),
bound `scoped` by the provider (each resolver made by the container with its options as named arguments),
`Exceptions\UnknownRevisionException` (`unknown($value, $accepted)`, both public). `RevisionsConfig::$resolvers` is a
list of `[class, options]`, defaulting to the header resolver; a resolver or a class `default` that is not an
instantiable `RevisionResolver` is refused, as are options that are not named. Decision **D57** (an unknown value stops
the chain; exact backed-value match, empty is absent; a resolver default answering `null` gives the head). Fixtures
`Resolvers\AccountPinnedResolver` (bearer token → pinned revision), `AbstractResolver`. Coverage 99.65 %, Infection MSI
98 %. Review: `ResolverChain` no longer implements `RevisionResolver` (as a default it would build itself); abstract
resolvers refused; `RevisionValue` keeping a case of the enum and comparing an int by its digits recorded in D57;
docblocks and §14 updated. Deviations: `UnknownRevisionException` is created here (the resolvers throw it), US 8.3
renders it; `ResolverChain` takes the enum to give the head. Follow-up: US 8.3 bounds the echoed client value and does
not report the exception (bullet added).

#### US 8.3 — `ResolveRevision` middleware · M · depends US 8.2

- Unknown value → `UnknownRevisionException` rendered **400** (JSON body naming the accepted values); stores the
  revision in the scope; response header; `Vary` in header / query mode; `Deprecation` / `Sunset` from `meta()`
  (midnight of the day in the application timezone, D30).

- Follow-up of US 8.2: `UnknownRevisionException` echoes the client value as sent: bound its length in the 400 body and
  do not report the exception (a client error, not a server one).

Tests: `test_an_unknown_revision_is_answered_with_400`, `test_the_response_carries_the_revision_header`,
`test_a_deprecated_revision_adds_deprecation_and_sunset_headers`, `test_header_mode_adds_vary`.

**Done** (2026-11-12): `Laravel\Http\ResolveRevision` (`final readonly`, alias `revision` registered by the provider):
the `ResolverChain` result becomes the base of the request's `RevisionScope`; the response gets `response_header`,
`Vary` appended (`ResolverChain::headers()`), `Deprecation: @<Unix time>` and `Sunset:
<HTTP-date>` as soon as declared, at midnight in `app.timezone` (UTC without one). An unknown revision is answered 400
by the middleware (`{message, revision, accepted}`, `JSON_INVALID_UTF8_SUBSTITUTE`), never reported;
`UnknownRevisionException::unknown()` cuts the value to 64 characters (US 8.2 follow-up). Decision **D58**, taken with
the maintainer for the header timing and format (RFC 9745 / RFC 8594, sent as soon as declared). Fixture: `Spring26`
gets `sunsetAt: '2027-05-01'`. Tests beyond the story's: the controller runs in the revision, the configured header
name, invalid UTF-8, no dates no headers, query mode no `Vary`, a `Vary` of the controller kept, the UTC fallback.
Review: the 400 carries the `Vary` too; the alias is registered only when the application defines none; `model.md`
§2 declares the new `sunsetAt`; an unknown value from the query is answered 400 too. Deviations: `Vary` covers the
headers of the `HeaderResolver`s only (the story says "in header / query mode": a query parameter is part of the URL,
so query and path mode need none); custom resolvers reading headers: Q19, decided after the story as D59 (the
optional contract `Laravel\Http\VariesOn`, implemented by `HeaderResolver` and collected by the chain). Laravel-layer
classes are tested in `tests/Feature/` only, like the provider. Coverage 99.66 %, Infection MSI 97 %.

#### US 8.4 — Path mode · M · depends US 8.3

- `Route::revisioned(Closure)` (prefix `{revision}` constrained to the enum values); the middleware forgets the
  parameter and sets `URL::defaults(['revision' => …])`.

Tests: `test_a_revisioned_route_resolves_the_revision_from_the_path`,
`test_the_controller_does_not_receive_the_revision_parameter`, `test_generated_urls_keep_the_revision`.

**Done** (2026-11-12): `Laravel\Routing\RevisionedRoutes::group()` behind the macro `Router::revisioned()` (registered in
`boot()`): prefix `{parameter}` from `RevisionsConfig::pathParameter()` (the first `PathResolver`'s option, else
`revision`; `ConfigurationException::pathModeWithoutResolver()` without a `PathResolver`, `invalidValue()` for a
non-string option), constraint on the backed values, `revision` middleware. `ResolveRevision` forgets the parameters
of `ResolverChain::parameters()` (`PathResolver::parameter()`) and sets them as URL defaults. PHPStan:
`PHPStan\RevisionedRouteExtension` declares the macro on the `Router` and the `Route` facade (a stub file was tried:
PHPStan ignores a stub's class-level `@method`), `CustomTypeMethod` generalized as `DeclaredMethod` (static or not, side
effects or not); the phpat rule of the tooling allows `RevisionedRoutes` (its signature types the macro). Decision
**D60**. Tests beyond the story's: a request resolved by header generates path URLs, a path naming no revision is a
404, the parameter option, a group already under `revision` runs it once, the macro without `PathResolver`. Coverage
99.5 %, Infection MSI 97 %. Deviations: the macro adds the `revision` middleware (the story leaves it implicit); URL
defaults are set on every request the middleware handles, not only on revisioned routes (the parameter name is reserved
for path mode, D60). Review: a `PathResolver` as `default` counts for the macro; the macro reads the router's
container, not the booting application; the macro listed in the allowed global state; D60 notes `route:cache` and the
reserved name.

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

**Done** (2026-11-12): `Laravel\Resources\RevisionedResource` (the bridge declares a response; `resolve()` projects
the descriptors of `toArray()` with the container's `Projector` on the shape of `current()`, then walks the
`filter()`ed `toArray()` to keep its order, its plain values and its native conditions; `Arrayable` values rendered as
arrays), `Laravel\Resources\DescribeSubject` (`@internal`, the permissive subject of the compilation, with an empty
`Request`), `Laravel\Resources\EloquentSourceReader`, `Schema\EagerSchema` (nullable `any`, always conditional),
`Descriptor::whenLoaded()` / `Presence::loaded()`, `Projection\SourceReader` turned into an interface
(`PlainSourceReader`, new `loaded()`), `DefinitionException::failedDescribing()`, the `Projector` bound `scoped` in the
provider. Decision **D61** (permissive subject chosen by the maintainer). Tests beyond the story's: pagination,
`additional()`, the order of `toArray()`, `Arrayable`, lenient reporting, the request given to `resolve()`, a shape
independent of the request served, a nested resource omitting a missing native condition, a failing plain value,
`ResourceInvariantTest` (the invariant over `Fixtures/Resources`, JSON Schema half with US 10.1), the provider's
`Projector`. Coverage 99.7 %, Infection covered MSI 96 %. Benchmark: now renders `PlainOrderResource` (the trait on
the plain `Order`) in a bare container; the reference moves from ×19.4 to ×22.0 of the plain resource (the scenario
changed: `filter()`, order walk and `Arrayable` pass of the resource on top of the projector, not a regression).
Deviations: a nested resource renders only its shape's fields (a native condition met inside it renders nothing, one
missing omits its field, declared keys memoized per class); `whenLoaded()` takes the relation (no default key).
Review: eager fields conditional, empty request at compilation, `failedDescribing()`, invariant over the resources,
provider test, docs rows. Follow-ups: US 9.2 reports `eager-value` (an `EagerSchema` in the head) and
`native-condition` (it needs the raw `toArray()`: the bridge drops `MissingValue` / `MergeValue`); US 10.1 exports an
`EagerSchema` as an optional `any`.

#### US 8.6 — Validation rules of a shape · L · depends US 5.2, US 8.1

- `Laravel\Validation\RuleCompiler` + one `RuleMapper` per `Kind`: rules under the **names of the revision**, with the
  converters' `extraRules()` (`numeric`), the enum rule, nested `*.` keys, the user `rules()`, `required` / `nullable`
  / `sometimes`.

Tests: `test_rules_use_the_names_of_the_client_revision`, `test_a_string_to_int_change_adds_numeric_to_the_old_rules`,
`test_nested_rules_use_dotted_keys`, `test_user_rules_are_kept_as_given`.

**Done** (2026-11-12): `Laravel\Validation\RuleCompiler` (`compile(Shape, descriptors)`, singleton of the provider on
the application's `Shapes`), `Laravel\Validation\RuleMapper` and `Mappers\StringRules`, `NumberRules`, `DateRules`,
`EnumRules`, `ArrayRules`, `FixedRules`; `DefinitionException::rulesOfAResponse()`, `unknownKind()`. Decision **D62**
(with the maintainer: presence rules as declared, `date_format:` of the type, known string formats mapped, and after
the review a required child of a parent the client may leave out gets `required_unless:<parent>,null`). Tests beyond
the story's: the fields added later, an enum target adds `in:`, only the first up conversion, presence order and the
nullability at the revision, nested resources and the query shape, the `was()` schema's children and items, the
rules of a `was()` schema, dates, merged collisions, a replaced mapper, the refusals, the converters given, and
`ShapeRulesTest` running Laravel's validator (old names, a resource left out, a `null` item). Coverage 99.7 %,
Infection covered MSI 98 % (equivalent mutants: the default kind of a shape compiled by the `Compiler`, the converter of
a registered step, a single extra rule). Deviations: the story's `test_a_string_to_int_change_adds_numeric…` is
`…adds_integer…` (the built-in string → int gives `integer`, string → float `numeric`, both tested). Review: the guard
of optional parents (D62, `required_with` replaced by `required_unless` since an empty object counts as absent for
Laravel), the default mappers as a `match` with checked keys, the step bound through `ConversionChain::bind()`, one
test class per mapper, the exception tests. Follow-ups: US 8.7 calls `RuleCompiler::compile()` with the request's
shape and descriptors; US 10.1 exports the same presence (a required child of an optional parent is required within
it, as in JSON Schema).

#### US 8.7 — `RevisionedRequest` · L · depends US 7.3, US 8.6

- Trait for `FormRequest`: `body()`, `queryParameters()` (D63); `rules()` = rules of `Shape(current())`; after
  validation, the upgrader; `validated()` / `safe()` return the head input; the `upgrade()` hook of the request is the
  `UpgradesInput`; a `ConversionException` becomes a validation error on the client's key.
- Decide Q18 first (§6): how a user `up:` closure rejects an input (a public `ConversionException` constructor or an
  exception contract caught by the layer).

Tests (Feature): `test_a_genesis_client_is_validated_with_its_own_names`,
`test_the_controller_receives_the_head_input`, `test_an_invalid_enum_value_is_a_validation_error_on_the_old_key`,
`test_query_parameters_are_validated_and_upgraded`.

**Done** (2026-11-12, D63, Q18 closed). `Laravel\Requests\RevisionedRequest` (trait: `body()`, `queryParameters()`,
`protected upgrade()`, `rules()`, `validated()`, `safe()`, the upgrade as the last validator `after` hook, once per
validator), `Laravel\Requests\RequestHook` (`@internal` adapter to `UpgradesInput`), `Exceptions\InvalidInputException`
(`at($key, $cause)`, `key`, `reason`), `ConversionException::invalid()` with `$reason` / `$declarationError`,
`DefinitionException::declaredTwice()`, `UpgradeRun::$client`; the `Upgrader` threads the client's key (names at the
client's revision, `a.b`, `a.0`, nested resources) and wraps rejections; the `Upgrader` is a singleton of the provider.
Fixtures `tests/Fixtures/Requests/{StoreOrder,ListOrders,Conflicting,HeadConflict}Request`,
`Declarations/LineDeclaration`. Tests beyond the story's: the input kept as sent, a head client, an `up:` closure's
message, the `in` message with the request's attribute names on a renamed key, collisions at the revision and at the
head, the application's `after` hooks first, `validated()` running the validation, one hook per validator, a validator
given replacing the head, a failed validation skipping the upgrade; in `UpgraderTest` the client keys and a default
or a composite added later rejected as a declaration error. Coverage 99.6 %, Infection covered MSI 93 % on the new code
(equivalent: the `protected` visibilities of the trait). Deviations: `query()` is `queryParameters()` (`Request::query()`
exists, decided with the maintainer); the upgrader throws `InvalidInputException` instead of the raw `ValueException` /
`ConversionException` (D54 amended); the story's enum test is satisfied by the `Enum` rule of D62 before the upgrade
(the converter path is tested on `qty`). Review: a value the client never sent is not its error (declaration error),
head-key collisions, a stale head after `setValidator()`, the `failed()` difference documented. Follow-ups: US 9.2 adds
a check code for a body / query name collision at any revision (`request-name-collision`: today thrown only at request
time); README (US 11.1): read the head from `validated()` / `safe()`, never `input()`.

#### US 8.8 — Outside HTTP · S · depends US 8.1

- `Laravel\Queue\CapturesRevision`: the revision at dispatch is stored on the job and restored with `using()` when it
  runs.

Tests: `test_a_job_runs_in_the_revision_of_its_dispatch`.

**Done** (2026-11-13, D64). `Laravel\Queue\CapturesRevision` (opt-in **marker interface**, not a trait: nothing to
share, and a trait would clash with a job's `middleware()` or `SerializesModels`) and `Laravel\Queue\CapturedRevisionHandler`
(`@internal`): a payload hook registered by the provider stores the backed value of `current()` (`ark4ne:revision`) and
swaps the payload's `CallQueuedHandler@call` for the handler, which runs `call()` / `failed()` inside `using()`.
Deviation: the revision is stored in the **payload**, not on the job, and restored with `using()` around Laravel's
handler (no `setBase()`: nothing leaks, a `sync` job in a request included). A revision the enum no longer knows fails
the attempt (`UnknownRevisionException`), `failed()` then runs in the base revision. Only `ShouldQueue` jobs (queued
notifications, mailables, listeners and broadcasts are wrapped by Laravel); a `PendingDispatch` returned out of
`using()` pushes in the outer revision (documented). Extra tests: the base revision of an unmarked job, `failed()`,
an unknown and a missing stored revision, the `sync` queue, a request through the `revision` middleware, a chain.

---

### E9 — Check and lock

Goal: CI catches every inconsistent declaration and every change to a published revision.

#### US 9.1 — Diagnostics and checker · S · depends US 5.2

- `Support\Diagnostic`, `Diagnostics`, `Severity`; `Checking\Check`, `Checker`, `CheckContext`; `ClassFinder`
  contract.

Tests: `test_a_diagnostic_names_its_class_field_and_revision`, `test_the_checker_runs_every_check_on_every_class`.

**Done** (2026-11-13): `Support\Severity`, `Diagnostic` (`isError()`; `revision` is the backed value as a string,
`Support` cannot see `Revision`), `Diagnostics` (ordered, `errors()`, `warnings()`, `hasErrors()`, countable,
iterable); `Checking\Check`, `CheckContext` (`shape()`, `error()`, `warning()`, filling the target class and refusing
a revision of another enum), `Checker` (each check on the enum first — `CheckContext::$class` `null` —, then on every
class of the finder; one fresh `Shapes` per run shared by its checks), `ClassFinder`.
`DefinitionException::noShapeForRevisions()`. Deviations: `PathClassFinder` implements `ClassFinder` here rather than
in US 9.3 (as architecture.md said); `Severity` has no test class (no behaviour). Infection is not installed, mutation
testing not run. Follow-ups: US 9.2 catches what `CheckContext::shape()` throws per class (D39) — until then
`Checker::run()` lets a declaration error through; US 9.3 gives the `Checker` the application's `Compiler` (its
`Configuration`: `missing_default`, D47, changes `required-since-without-default`).

#### US 9.2 — Check rules · M · depends US 9.1

- One class per code of `model.md` §9 except the lock ones.
- Spike M0 findings to address: F8, F10 (§3.1).

Tests: one passing and one failing declaration per code, e.g.
`test_a_required_field_added_later_without_default_is_reported`, `test_a_lossy_conversion_without_cast_is_reported`,
`test_a_rule_keyword_overriding_the_type_is_reported` (`schema-override`, D36: compare the exported keywords of the
type with `TypeDefinition::$jsonSchema`). The repeated `since()` / `until()` calls of model.md §4.4 rule 5 are read
from `Descriptor::repeatedBounds()` (US 3.1). Declaration errors are caught per class and reported under their code, else
`definition-error` (D39): `test_a_declaration_error_is_reported_and_the_check_goes_on`.

**Done** (2026-11-13): `Checking\Checks\` `OrderMissingCase`, `OrderDuplicateCase`, `BoundRepeated`,
`HistoryBeforeSince`, `HistoryAfterUntil`, `ConversionMissing`, `ConversionLossy`, `NameCollision`,
`RequiredSinceWithoutDefault`, `EagerValue` (listed by `Checker::builtIn()`); `Laravel\Checking\NativeCondition` with
`RevisionedResource::revisionedNativeConditions()` (`@internal`); `Checking\Fields` (`@internal` walker by path of head
keys, conversion steps once per kind, field and `was()`); `Checker` builds each class first (descriptors, shapes at
every revision, every kind) and reports what it throws under `DefinitionException::$checkCode` (`lifetime-inverted`,
`since-older-than-group`, `until-newer-than-group`, `history-unordered`, `unknown-resource`) or `definition-error`;
an invalid `order()` stops the run after the enum; `Compiler::describe()` / `converters()`; `CheckContext::order()`,
`kinds()`, `descriptors()` (memoized), `converters()`. Decisions taken with the maintainer: **D65** (`native-condition`
a warning, in the Laravel layer; `schema-override` moved to US 10.1) and **D66** (any exception building a class is one
error, the class skipped). Spike findings: F8 (each shape checks its own direction), F10 (`history-after-until`).
Review: a `DefinitionException` inside a `RevisionedResource::toArray()` lost its code through `failedDescribing()`
(fixed: rethrown as it is, `InvertedResource` fixture); descriptors memoized per class; one failing declaration per
thrown code through the checker; an invalid order reported unless an `order-*` check did; a `required()->since()`
field retired before the head reported (shapes at every revision); `declaredTwice()` carries no code (thrown at run
time only). Coverage 99.56 %; Infection not installed, mutation testing not run. Deviations: the codes thrown when
declared are not classes (a literal on `DefinitionException`); `schema-override` and its test moved to US 10.1 (D65);
`test_a_rule_keyword_overriding_the_type_is_reported` lives there; `ConversionLossy` re-finds the converter of a
registered step by its types (as US 4.3 planned). Follow-ups: US 9.3 registers `Checker::builtIn()` plus
`NativeCondition` and gives the `Checker` the application's `Compiler` (its `Configuration` and `Converters`).

#### US 9.3 — `revision:check` and the PHPUnit assertion · S · depends US 9.2, US 8.1

- `Laravel\Discovery\PathClassFinder` (`Revisioned` classes under `discovery.paths`, plus `discovery.classes`):
  shipped by US 5.3, implements the `ClassFinder` contract since US 9.1; command `revision:check` (exit code ≠ 0 on error, table output, `--format=json`, `--list` prints the discovered
  classes); trait `Testing\InteractsWithRevisions` (D28) with `assertRevisionsAreConsistent()`.

Tests: `test_the_check_command_fails_on_an_error`, `test_the_assertion_fails_with_the_diagnostics`,
`test_explicit_classes_outside_the_paths_are_checked`.

**Done** (2026-11-13): `Laravel\Console\CheckCommand` (`revision:check`: table of diagnostics and a summary, fails on
an error, warnings alone pass; `--format=json` with `classes`, `errors`, `warnings`, `diagnostics`; `--list`, as a JSON
list under `--format=json`; an unknown format or an invalid discovery entry fails with its reason), container
bindings of `Checking\ClassFinder` (`PathClassFinder` over the discovery) and `Checking\Checker` (the enum,
`Checker::builtIn()` plus `NativeCondition`, a `Compiler` with the application's `Configuration`), both built anew;
`CacheCommand` now resolves the `ClassFinder`; `Support\Diagnostic::toArray()`; `Testing\InteractsWithRevisions`
(`assertRevisionsAreConsistent()`, a `LogicException` without the application). Fixtures `tests/Fixtures/Checked/`.
Coverage 99.67 %; Infection not installed. Review: `src/Testing/` resolves from the test case's `$app` (architecture.md
§2 and §16 reworded; the "Pure core" bullet of CLAUDE.md still says only `src/Laravel/`, left to the maintainer), a
configuration error of the discovery reported instead of thrown, `--list` honours `--format=json`, the classes of the
explicit-classes test pinned. Deviations: `PathClassFinder` was already shipped (US 5.3) and implements `ClassFinder`
since US 9.1; the command and the `Checker` each scan the discovery (two scans, a simpler contract); `withRevision()`
of D28 stays in US 10.4.

#### US 9.4 — Lock · M · depends US 10.1, US 9.3

- `Checking\Lock` writes / compares normalized snapshots (`model.md` §14 D11) and the recorded `order()`; command
  `revision:lock` (all but the head, `--revision=`); checks `lock-mismatch`, `lock-order-changed`.

Tests: `test_a_field_leaking_into_a_locked_revision_fails_the_check`, `test_moving_a_locked_revision_fails_the_check`,
`test_the_head_is_never_locked_by_default`.

**Done** (2026-11-14): `Checking\Lock` (`locked()`, `has()`, `read()`, `write()`, `capture()`, `normalize()`: the
exported JSON Schema without its documentation keywords, keys sorted), `Checking\LockedRevision` (one
`lock_path/<value>.json`: `previous` and the schemas by class and kind), `Exceptions\LockException`, checks
`Checking\Checks\LockMismatch` (per top-level field under its head key, per other keyword, per class or kind added —
when it has fields there — or removed; on the enum: unreadable files, classes no longer discovered; snapshots read
once per run) and `LockOrderChanged` (the recorded `previous`, a case gone), `Laravel\Console\LockCommand`
(`revision:lock {--revision=}`), `CheckContext::$classes` / `revision()` / `export()`. Decision D69. Tests:
`test_a_field_leaking_into_a_locked_revision_fails_the_check` (`LockMismatchTest`),
`test_moving_a_locked_revision_fails_the_check` (`LockOrderChangedTest`), `test_the_head_is_never_locked_by_default`
(`RevisionLockTest`). Coverage 99.64 %; Infection not installed. Review: an invalid `order()` no longer makes
`lock-order-changed`, `name-collision` or `required-since-without-default` throw on the enum (the last two predate
this story), a nested resource that does not build is left to its own diagnostic, an empty schema is written as
`{}`, `scandir()` instead of `glob()`, `revision:lock` reports any exception, a class or kind without field is not
"new" in a lock (D69 refined). Deviations: the lock checks take the `Lock`, so they are added by the Laravel binding
of the `Checker` rather than `Checker::builtIn()`; `LockMismatch` keeps the snapshots of a run in its instance (read
on the enum, which every run checks first).

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
- Moved from US 9.2 (D65): the check rule `schema-override` (warning), comparing the keywords the exporter derives from
  the type with `TypeDefinition::$jsonSchema`: `test_a_rule_keyword_overriding_the_type_is_reported`.

Tests: `test_each_revision_exports_its_own_schema`, `test_a_projected_document_matches_the_schema_of_its_revision`.

**Done** (2026-11-14): `Export\SchemaVisitor` (`visit(Shape)`), `Export\JsonSchemaExporter` (draft 2020-12:
`export($class, $kind, $revision)`, `visit()`, `typeKeywords()`; `title`, revision `meta()` in `$comment`, types,
constraints, enum values, nullable in `type` / `enum` or `anyOf`, `required` per D67, descriptions, examples,
`deprecated`, custom fragments, dates in their wire format with a derived `pattern`, rule keywords last; nested
resources in a flat `$defs`, `'#'` for the exported class; objects open), `Export\DatePattern` and
`Export\RegexPattern` (`@internal`), check `schema-override` (`Checking\Checks\SchemaOverride`, in
`Checker::builtIn()`), `revision:export` (`Laravel\Console\ExportCommand`). Decisions D67 (mapping, `$defs`, dates,
command output) and D68 (PCRE patterns, `TypeDefinition::$nullable`). Tests: `test_each_revision_exports_its_own_schema`
(`RevisionExportTest`), `test_a_projected_document_matches_the_schema_of_its_revision` (`ProjectionInvariantTest`, opis),
`test_a_rule_keyword_overriding_the_type_is_reported` (`SchemaOverrideTest`). Coverage 99.65 %; Infection not
installed. Review: patterns exported without delimiters (or described when flags forbid it), the nullability of items
and children carried by `TypeDefinition` (also through `ShapeSelector::typeOf()` for a `was()` schema), the
compiled-shapes cache format bumped to `2`. Deviations: `meta()` goes to `$comment` only (`description` stays the
field's); `schema-override` compares the field's own type and does not walk the children of a `was()` schema; the
`$defs` are per document until US 10.2 (`Components`).

#### US 10.2 — `$ref` components · M · depends US 10.1

- `Export\Components`: an object or custom type used in several classes is exported once and referenced; its
  history applies in every reference.

Tests: `test_a_shared_object_is_exported_as_a_component`.

**Done** (2026-11-14): `Export\Components` (the `$defs` of one document: nested resources reserved then filled,
components defined at first use, `reference()` a JSON Pointer), `Schema\ObjectSchema::component()`,
`Definition\TypeDefinition::$component`, `DefinitionException::componentConflict()` (with `$field`) and
`invalidComponentName()`, check `Checking\Checks\ComponentConflict` (`component-conflict`, in `Checker::builtIn()`);
the exporter threads the registry and the field path; a custom type is now a `$ref` to `#/$defs/<name>`; compiled
shapes cache format `3`. Fixtures `Address`, `ShippingDeclaration`, `WarehouseDeclaration` (projected by the
invariant test, so validated with their `$defs`). Decision D70. Test `test_a_shared_object_is_exported_as_a_component`
(`JsonSchemaExporterTest`). Coverage 99.6 %; Infection not installed. Review: a component named as a nested resource
is a conflict (`reserve()` / `fill()`), the conflict test of `LockMismatch`, one component used under the head and
under a `was()` schema at one revision is a conflict (D70, tested), docs reworded (names at `R` below a `was()`
schema). Deviations: "exported once" means once per document (D70: documents stay self-contained, a generator lifts
`$defs` by name); the first conflict of a document stops its export, so `component-conflict` reports one conflict per
document and revision at a time.

#### US 10.3 — Changelog · M · depends US 10.1

- `Export\Changelog`: diff of consecutive shapes per class (added, removed, renamed from the `History`, type changed),
  Markdown with `meta()`; command `revision:changelog {--from=} {--to=}`.

Tests: `test_the_changelog_lists_a_rename_as_a_rename`, `test_the_changelog_uses_the_revision_descriptions`.

**Done** (2026-11-14): `Export\Changelog` (`changes()`: per class, kind and revision against the one before it, fields
matched per level by head key, walked through object children and list items where the head is in force on both
sides; `markdown()`: newest first, `meta()` heading each revision, "No change." when nothing changes), `Export\Change`
(`describe()`), `Export\ChangeKind`, `Laravel\Console\ChangelogCommand` (`revision:changelog {--from=} {--to=}`,
`--from` excluded, `--to` included), `JsonSchemaExporter::DOCUMENTATION` (moved from `Lock`, shared by the lock and the
changelog). Decision D71. Tests `test_the_changelog_lists_a_rename_as_a_rename`,
`test_the_changelog_uses_the_revision_descriptions` (`ChangelogTest`). Coverage 99.6 %; Infection not installed.
Review: the documentation of children and items is left out of a type compared whole, the dead `anyOf` strip removed,
the command's catch-all explained (the declarations are application code, D66). Deviations: the "now required /
optional" line agreed first is left out (the presence of a field comes from its head, D46, so it never changes between
two revisions); the days of `meta()` are rendered by the changelog and the exporter separately (two formats: a
paragraph and a `$comment`).

#### US 10.4 — Testing helpers · M · depends US 10.1, US 8.3

- `Testing` (D28): `TestResponse` macro `assertMatchesRevisionSchema(R)`; `withRevision(R)` (header or path according
  to the configuration) and the contract-test helper (head fixture rendered at `R`, validated) on the trait
  `InteractsWithRevisions`.

Tests: `test_with_revision_sends_the_configured_header`, `test_assert_matches_revision_schema_fails_on_an_extra_field`.

**Done** (2026-11-14): on `Testing\InteractsWithRevisions`, `withRevision()` (the first resolver of the chain that can
carry the revision: a header, the route parameter of path mode through `URL::defaults()`, or the query appended in
`prepareUrlForRequest()` unless the request names it, its fragment kept; a `LogicException` with a custom resolver
alone; an invalid `name` option throws `ConfigurationException::invalidValue()`) and `assertRendersAtEveryRevision()` (a
`JsonResource` resolved under `Revision::using()`, any other class projected by the application's `Projector`, an
empty rendering validated as an object); `Testing\TestResponseMacros` (the macro `assertMatchesRevisionSchema()`,
registered by `setUpInteractsWithRevisions()`: the revision of the response header by default, a missing or unknown one
and a non-JSON body failing the test, the `data` of a wrapped body), `Testing\RevisionSchema` (validation through
`opis/json-schema`, suggested, a list item by item), `JsonSchemaExporter(closed: true)`, `HeaderResolver::DEFAULT_NAME`,
`QueryResolver::DEFAULT_NAME`. Decision D72. Tests `test_with_revision_sends_the_configured_header`,
`test_assert_matches_revision_schema_fails_on_an_extra_field` (`TestingHelpersTest`), `RevisionSchemaTest`. Coverage
99.6 %; Infection not installed. Deviations: `withRevision()` also covers the query mode, and the contract test is a
helper of the trait (`assertRendersAtEveryRevision()`); `JsonSchemaExporter::objects()` moved from `LockedRevision`
fixes `revision:export`, which wrote an `any` field as `[]` instead of `{}`; a PHPStan extension
(`PHPStan\RevisionSchemaAssertionExtension`, in `extension.neon`) declares the macro; `TestResponseMacros` is excluded
from the phpat rule on `Testing` (it reads `TestResponse`); the branch throwing without `opis/json-schema` is not
tested (the package is a dev dependency); `TestResponseMacros` is covered by the feature tests only.

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
E5 ─────────────────────────────────────── 5.1 ─ 5.2 ─ 5.3 ─ 5.4 ─ 5.5  └─ 8.8
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
| **M3 — Safety** | E9, US 10.1, US 5.3, US 5.4, US 5.5 | every diagnostic code covered; lock in CI; benchmark gate |
| **M4 — Release** | US 10.2–10.5, E11 | 1.0.0 ready to tag; X1 and X2 unblocked |

Estimate (S = 1 d, M = 2.5 d, L = 4.5 d): E0 4 · E1 4.5 · E2 9.5 · E3 10 · E4 7 · E5 8.5 · E6 6 · E7 6 · E8 21 ·
E9 7 · E10 11 · E11 4.5, plus US 2.6, 6.4 and 5.5 (4.5) → about **104.5 days** of nominal work.


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
| F11 | Value errors: `normalize()` must report through the `ValueErrorHandler` with class, field, revision; a non-nullable `null` is not reported; `$subject->{$key} ?? null` calls `__isset()` and hides Eloquent strict-mode errors §6.2 wants visible | 6.1, 6.4 (addressed: US 6.1 reads without `isset()`, US 6.4 reports a non-nullable `null` with class, field, revision) |
| F12 | Tests to add: round trip (down then up gives the head value back: 1250 → 12.5 → 1250), upgrades from every revision, an `until` field the hook does not consume, `mixedRevisions` / `invalidOrder` | 1.1, 7.1 (6.3: deferred to 7.1) |
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
| Q3 | Query parameter arrays (`filter[status]`, `sort=-total`): do renames apply inside values (`sort`) or only keys? | US 8.7 | **Decided** (2026-11-11): renames apply to **keys** only; a change of the values is a revision of its own, declared with `was()` (D22) |
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
| Q18 | Exceptions thrown by a user `up:` closure (US 4.2). Today only a `ConversionException` (raised by the library's converters) becomes a validation error on the client's key (US 8.7); any other exception from a closure bubbles up as a 500. Leaning towards letting a closure reject an input for its revision, either with a public `ConversionException::invalid($message)` or with an exception **contract** (an interface the Laravel layer catches) that an application exception may implement: compare both when US 8.7 starts | US 4.2 | **Decided** (2026-11-12): `ConversionException::invalid($message)`; the upgrader wraps a rejected value in `InvalidInputException`, a validation error on the client's key; `missing()` and any other exception stay 500 (D63) |
| Q19 | `Vary` of custom resolvers (US 8.3). The middleware varies on the headers of the `HeaderResolver`s only; a custom resolver reading another header (an account pinned by `Authorization`, a tenant header) leaves its `Vary` to the application (a request with `Authorization` is not stored by a shared cache unless allowed, RFC 9111 §3.5). Proposed: an optional contract (`VariesOn::headers(): list<string>`) a resolver implements and the chain collects | US 8.3 | **Decided** (2026-11-12): as proposed (D59) |

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
| US 5.3 | Compiled file, `revision:cache` | M | 5.2, 8.1 | M3 | done |
| US 5.4 | Benchmark gate | M | 6.1 | M3 | done |
| US 5.5 | Projection cost | M | 5.4 | M3 | done |
| US 6.1 | Projector and source reader | M | 5.2 | M2 | done |
| US 6.2 | Nested projection | M | 6.1 | M2 | done |
| US 6.3 | Invariant test | S | 6.2 | M2 | done |
| US 6.4 | Value errors | S | 6.1 | M2 | done |
| US 7.1 | Upgrader | M | 5.2 | M2 | done |
| US 7.2 | The `upgrade()` hook | S | 7.1 | M2 | done |
| US 7.3 | Nested upgrade | M | 7.1 | M2 | done |
| US 8.1 | Service provider and configuration | S | 1.3 | M2 | done |
| US 8.2 | Resolvers | M | 8.1 | M2 | done |
| US 8.3 | `ResolveRevision` middleware | M | 8.2 | M2 | done |
| US 8.4 | Path mode | M | 8.3 | M2 | done |
| US 8.5 | `RevisionedResource` | M | 6.2, 8.1 | M2 | done |
| US 8.6 | Validation rules of a shape | L | 5.2, 8.1 | M2 | done |
| US 8.7 | `RevisionedRequest` | L | 7.3, 8.6 | M2 | done |
| US 8.8 | Outside HTTP | S | 8.1 | M2 | done |
| US 9.1 | Diagnostics and checker | S | 5.2 | M3 | done |
| US 9.2 | Check rules | M | 9.1 | M3 | done |
| US 9.3 | `revision:check` and assertion | S | 9.2, 8.1 | M3 | done |
| US 9.4 | Lock | M | 10.1, 9.3 | M3 | done |
| US 10.1 | JSON Schema export | M | 5.2 | M3 | done |
| US 10.2 | `$ref` components | M | 10.1 | M4 | done |
| US 10.3 | Changelog | M | 10.1 | M4 | done |
| US 10.4 | Testing helpers | M | 10.1, 8.3 | M4 | done |
| US 10.5 | `revision:prune` | S | 9.2 | M4 | todo |
| US 11.1 | README and guide | S | M3 | M4 | todo |
| US 11.2 | Agent skill | M | 11.1 | M4 | todo |
| US 11.3 | Release 1.0.0 | S | all | M4 | todo |
