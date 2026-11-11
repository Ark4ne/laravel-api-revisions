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
8. No static state outside `Support\Memo` and `RevisionScope::provider()`.
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

#### US 0.3 — Architecture test · S

*As a maintainer, I want the layers enforced by the analysis, so that no story can break them silently.*

- `tests/Architecture/ArchitectureTest.php` (phpat): layers of `architecture.md` §2 left to right; `Exceptions`
  depends only on `Support`; **no layer left of `Laravel` depends on `Illuminate`**; classes `final` except the
  allowed base classes; traits only in the allowed list.
- An empty namespace per layer is fine; each rule is proven by a temporary violating class during the story (not
  committed).

#### US 0.4 — Continuous integration · S

- `.github/workflows/ci.yml`: tests (PHP 8.3/8.4/8.5 × Laravel 12/13 × lowest/highest), static (validate, PHPStan,
  CS), coverage (pcov, gate 90 %), mutation (blocking on `master`), audit (informative); `codecov.yml`.

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

#### US 1.2 — Revision metadata · S · depends US 1.1

- Attribute `Revisions\Meta(string $description = '', ?string $publishedAt = null, ?string $deprecatedAt = null,
  ?string $sunsetAt = null)`, `TARGET_CLASS_CONSTANT`; dates validated as `Y-m-d` at read time.
- `IsRevision::meta(): Meta` reads it by reflection, memoized; an empty `Meta` when absent.
- `Meta::isDeprecated(DateTimeInterface $now)`, `isSunset($now)`.

Tests: `test_meta_reads_the_attribute_of_the_case`, `test_a_case_without_meta_has_an_empty_meta`,
`test_an_invalid_date_in_meta_throws`.

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

#### US 2.2 — Dates and enums · S · depends US 2.1

- `Schema::date()`, `dateTime()` (format from `Configuration`), `Schema::enum(class-string<BackedEnum>)` (values and
  backing type in its `TypeDefinition`).
- Normalization: dates from `DateTimeInterface`, parsable string or timestamp → `DateTimeImmutable`, serialized with
  the type's format; enums from the case or its backed value (`::from()`) → the case, serialized as `->value`.

Tests: `test_enum_schema_exports_the_values_of_the_backed_enum`, `test_date_schema_uses_the_configured_format`,
`test_enum_schema_normalizes_a_backed_value_to_its_case`, `test_date_schema_normalizes_a_timestamp`.

#### US 2.3 — Lists and objects · M · depends US 2.1

- `Schema::list(Descriptor $items, $source = null)` (`min()` / `max()` on the size; any `iterable` normalized to a
  list), `Schema::object(array<string, Descriptor>, $source = null)`, nested freely; children keep their own history
  (E3).
- Subject of the children (`model.md` §6.1): an object with a source → the source's value; without → the parent's
  subject; a list → each item.

Tests: `test_an_object_describes_its_children`, `test_a_list_describes_its_items_and_size`,
`test_a_list_normalizes_any_iterable`.

#### US 2.4 — Escape hatch and conditions · S · depends US 2.1

- `Schema::rule(array $rules)` → `Kind::Any` unless `describe()`; `Schema\DescribesSchema::toSchema(): array` on a
  rule enriches the export (read by US 10.1).
- `->when(fn ($value, $subject) => …)`: the field becomes `conditional` (optional in the export); `->whenHas()`: a
  presence condition (runs before the source).

Tests: `test_a_rule_schema_is_any_without_description`, `test_a_conditional_field_is_marked_conditional`.

#### US 2.5 — Custom types · M · depends US 2.3, US 4.1

*As an application developer, I want to add my own type (`Schema::money()`), so that it carries its rules, its
documentation and its conversions everywhere.*

- `Schema\TypeRegistry`: `register(string $name, class-string<Descriptor>)`; `Schema::__callStatic()` serves it;
  unknown name → `DefinitionException::unknownType()`.
- A custom type declares its `TypeDefinition` (`Kind::Custom` + name + JSON Schema fragment), its rules and,
  optionally, its converters (US 4.4).
- Fixture `tests/Fixtures/MoneySchema` (`amount` int cents + `currency`).

Tests: `test_a_registered_type_is_available_on_schema`, `test_an_unknown_type_throws`.

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

#### US 3.2 — Groups · S · depends US 3.1

- `Schema::since(R, array $fields, mixed $default = new Undefined())`, `Schema::until(R, array $fields)`: return the
  array with each descriptor updated; a member's own newer `since` wins; an older one throws
  `DefinitionException::sinceOlderThanGroup()`; a member's own default wins.
- Nested in objects: a group inside `Schema::object([...])` works the same.
- Spike M0 findings to address: F10 (Q7) (§3.1).

Tests: `test_a_group_sets_since_on_every_member`, `test_a_member_newer_than_its_group_keeps_its_own_since`,
`test_a_member_older_than_its_group_throws`, `test_a_member_default_overrides_the_group_default`.

#### US 3.3 — `was()` · M · depends US 3.1, US 4.1

- `Descriptor::was(Revision $revision, ?string $name = null, ?Descriptor $schema = null, ?Closure $down = null,
  ?Closure $up = null)` → one `Schema\Change` in the `History`, ordered by revision whatever the call order.
- Two `was()` on the same revision, or a `was()` on the head: `DefinitionException::invalidHistory()`.
- A `schema:` carrying a source: `DefinitionException::sourceInHistory()` (older values derive from the head value).
- No effect on the head: the descriptor describes itself exactly as without `was()`.
- Spike M0 findings to address: F10 (§3.1).

Tests: `test_was_returns_a_new_descriptor`, `test_was_entries_are_ordered_by_revision_not_by_call`,
`test_was_on_the_head_throws`, `test_was_has_no_effect_on_the_head_type`.

#### US 3.4 — `ShapeSelector`: the shape of a revision · L · depends US 3.2, US 3.3, US 4.3

*As a maintainer, I want one pure function giving the shape of a class at a revision, so that the response, the
validation, the export and the lock never disagree.*

- `Compilation\ShapeSelector::at(array<string, Descriptor> $head, Revision $r): Shape` (`model.md` §4.5): visible
  fields (lifetime), for each: name, type, required / nullable, defaults, conversion plan down and up, with **chained
  inheritance** of omitted `was()` arguments from the next newer shape.
- Recursive into objects and lists (children have their own history).
- Name collisions at `R` are recorded on the `Shape` (reported by US 9.2), never thrown.
- Spike M0 findings to address: F4, F5, F6 (Q6) (§3.1).

Tests (the reference table of `model.md` §4.2, one test per row and per column):
`test_the_head_shape_ignores_every_was`, `test_a_rename_applies_up_to_its_revision_included`,
`test_an_omitted_name_is_inherited_from_the_next_newer_shape`,
`test_chained_type_changes_give_the_type_of_the_oldest_applicable_was`,
`test_a_field_until_a_revision_is_absent_from_the_next_one`, `test_nested_children_follow_their_own_history`,
`test_a_name_collision_is_recorded_on_the_shape`.

#### US 3.5 — Defaults and the `Undefined` sentinel · S · depends US 3.4

- `Support\Undefined` (singleton-free value object, `Undefined::is()`); a field `since(R)` has a default in every
  shape older than `R` (consumed by US 7.1); `Configuration::missingDefault` (`error` | `null`).

Tests: `test_undefined_and_null_defaults_are_distinct`, `test_missing_default_null_option_fills_null`.

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

#### US 4.2 — Explicit casts and closures · S · depends US 4.1

- `Cast::round()`, `floor()`, `ceil()`; `ClosureConverter` wraps `down:` / `up:` (always explicit).

Tests: `test_cast_round_rounds_half_up`, `test_a_closure_converter_is_explicit`.

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

#### US 4.4 — Conversions of custom types · S · depends US 2.5, US 4.3

- A custom type registers its converters with the `Converters` registry when registered in the `TypeRegistry`.

Tests: `test_a_custom_type_converts_to_a_float_with_its_own_converter`.

---

### E5 — Compilation and cache

Goal: the shape of a class is computed once per revision, without a subject, and can be cached in a file.

#### US 5.1 — Probe and compiler · M · depends US 3.4

- `Schema\Revisioned` contract: `static declarationKind(): DeclarationKind` (`Response` | `RequestBody` |
  `RequestQuery`) and the
  `@internal` bridge `describeRevisioned(): array<string, Descriptor>`.
- `Compilation\Probe` (Null Object subject); `Compiler::compile(class, R)`: instantiate on the probe, call the hook,
  `ShapeSelector::at()`. A source closure run during compilation is a bug: the probe throws on any access.

Tests: `test_compiling_a_class_runs_no_source`, `test_compiling_gives_the_shape_of_the_revision`.

#### US 5.2 — Shapes repository · S · depends US 5.1

- `Compilation\Shapes::for(class, R)` memoized via `Support\Memo`; `Shapes::flush()` (Octane, tests).

Tests: `test_a_shape_is_compiled_once_per_class_and_revision`, `test_flush_forgets_compiled_shapes`.

#### US 5.3 — Compiled file and `revision:cache` · M · depends US 5.2, US 8.1

- `CompiledShapes` writes / reads a PHP file (`var_export` of the shapes, keyed `class@revision`); `Shapes` reads it
  first. Commands `revision:cache` and `revision:clear` (Laravel layer), registered with
  `ServiceProvider::optimizes()`; file `bootstrap/cache/revisions.php` (`cache_path`).

Tests: `test_a_cached_shape_equals_the_compiled_one`, `test_projection_from_the_cache_runs_the_closures_of_the_instance`,
`test_artisan_optimize_builds_the_revisions_cache`, `test_optimize_clear_removes_the_revisions_cache`.

#### US 5.4 — Benchmark gate · M · depends US 6.1

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

#### US 6.2 — Nested projection · M · depends US 6.1

- Objects and lists projected recursively with their children's histories and subjects (`model.md` §6.1); `null`
  objects stay `null`; `ResourceSchema` projects the other class at the same revision.

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
  type, never the value itself); `LenientValues` casts (`(int)`, `(string)`…, the raw value for an unknown enum) and
  passes the error to a reporter (`Closure(ValueException): void`).
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

- `config/revisions.php` documented key by key (`model.md` §8); `Configuration` built from it (validated: `enum`
  implements `Revision`, resolvers exist); `RevisionScope` provider = `scoped` binding; publishable config.

Tests: `test_an_enum_not_implementing_revision_is_refused`, `test_the_scope_is_reset_between_two_requests`.

#### US 8.2 — Resolvers · M · depends US 8.1

- `RevisionResolver` contract; `HeaderResolver`, `QueryResolver`, `PathResolver`; chain built from the configuration,
  first non-null wins; `default`: `latest`, `oldest`, a backed value, or a resolver class.

Tests: `test_the_first_resolver_returning_a_revision_wins`, `test_the_default_applies_when_no_resolver_answers`,
`test_a_custom_resolver_pins_the_revision_of_an_account`.

#### US 8.3 — `ResolveRevision` middleware · M · depends US 8.2

- Unknown value → `UnknownRevisionException` rendered **400** (JSON body naming the accepted values); stores the
  revision in the scope; response header; `Vary` in header / query mode; `Deprecation` / `Sunset` from `meta()`.

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
`test_a_required_field_added_later_without_default_is_reported`, `test_a_lossy_conversion_without_cast_is_reported`.

#### US 9.3 — `revision:check` and the PHPUnit assertion · S · depends US 9.2, US 8.1

- `Laravel\Discovery\PathClassFinder` (`Revisioned` classes under `discovery.paths`, plus `discovery.classes`);
  command `revision:check` (exit code ≠ 0 on error, table output, `--format=json`, `--list` prints the discovered
  classes); `Testing\RevisionAssertions::assertRevisionsAreConsistent()`.

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

- `Testing`: `TestResponse` macro `assertMatchesRevisionSchema(R)`, test case helper `withRevision(R)` (header or
  path according to the configuration), contract-test helper (head fixture rendered at `R`, validated).

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
| US 0.2 | Static analysis and style | S | 0.1 | M1 | todo |
| US 0.3 | Architecture test | S | 0.2 | M1 | todo |
| US 0.4 | Continuous integration | S | 0.2 | M1 | todo |
| US 1.1 | Revision contract and order | M | 0.3 | M1 | todo |
| US 1.2 | Revision metadata | S | 1.1 | M1 | todo |
| US 1.3 | Current revision and `using()` | S | 1.1 | M1 | todo |
| US 2.1 | Descriptor, source, scalars | M | 0.3 | M1 | todo |
| US 2.2 | Dates and enums | S | 2.1 | M1 | todo |
| US 2.3 | Lists and objects | M | 2.1 | M1 | todo |
| US 2.4 | Escape hatch and conditions | S | 2.1 | M1 | todo |
| US 2.5 | Custom types | M | 2.3, 4.1 | M1 | todo |
| US 2.6 | Nested `Revisioned` classes | S | 2.3, 5.1 | M1 | todo |
| US 3.1 | `since()` and `until()` | S | 1.1, 2.1 | M1 | todo |
| US 3.2 | Groups | S | 3.1 | M1 | todo |
| US 3.3 | `was()` | M | 3.1, 4.1 | M1 | todo |
| US 3.4 | `ShapeSelector` | L | 3.2, 3.3, 4.3 | M1 | todo |
| US 3.5 | Defaults and `Undefined` | S | 3.4 | M1 | todo |
| US 4.1 | Converters and directions | M | 2.1 | M1 | todo |
| US 4.2 | Explicit casts and closures | S | 4.1 | M1 | todo |
| US 4.3 | Conversion chains | M | 4.2 | M1 | todo |
| US 4.4 | Conversions of custom types | S | 2.5, 4.3 | M1 | todo |
| US 5.1 | Probe and compiler | M | 3.4 | M1 | todo |
| US 5.2 | Shapes repository | S | 5.1 | M1 | todo |
| US 5.3 | Compiled file, `revision:cache` | M | 5.2, 8.1 | M3 | todo |
| US 5.4 | Benchmark gate | M | 6.1 | M3 | todo |
| US 6.1 | Projector and source reader | M | 5.2 | M2 | todo |
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
