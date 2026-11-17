# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`ark4ne/laravel-api-revisions` is a PHP library that describes **typed schemas** (`Schema::string()`, `Schema::int()`…)
and their **evolution through ordered revisions**, Stripe-style. The code declares the latest shape (the **head**);
older shapes are declared on the fields that changed (`since()`, `until()`, `was()`). From that one declaration the
library derives the response of every revision, the validation and upgrade of every request to the head, the
validation rules, a JSON Schema export, a changelog, a consistency check and a lock of the published revisions.
A pure-PHP core plus a Laravel layer, in one package.

Read before writing any code:

1. **`docs/specs/model.md`** — the decided behaviour (what the library does). Decisions in its §14.
2. **`docs/architecture.md`** — layers, patterns and conventions (how the code is organised).
3. **`docs/specs/epics.md`** — the epics, user stories, Definition of Done and the **tracking board** (§8).

- `ark4ne/laravel-json-api` and `ark4ne/laravel-openapi-generator` (sibling repositories under
  `~/works/ark4ne/`) are **models** for the architecture and the tooling, never dependencies. They will depend on this
  library (external epics X1, X2), not the reverse. `laravel-json-api` 2.1 already ships revisions with the **same
  inclusive bounds** but other rules: `model.md` §13 lists the differences — never copy its code as is.
- The design study (French, superseded where `model.md` disagrees) is the Claude Docs document
  https://claude.ai/artifact/XjJeW7sm8YpJppNZhTpKZK.

Targets: PHP `^8.3`, Laravel `^12.46 || ^13.0`. Only APIs present in every supported Laravel version.

## Commands

Available once US 0.1–0.2 are done:

```bash
composer test            # whole PHPUnit suite (Unit, Feature)
composer test:coverage   # coverage, fails under 90% of lines
composer stan            # PHPStan max + Larastan + phpat architecture rules + disallowed calls
composer cs              # code style check (PER-CS); composer cs:fix to fix
composer fixtures:update # rewrite tests/Feature/**/expected/ from the current output (review the diff)
composer benchmark       # projection time and memory, head vs oldest revision (fails on regression)
composer ci              # every blocking check except coverage and mutation

vendor/bin/phpunit --filter test_name
```

## Workflow

- **Work story by story** from `docs/specs/epics.md`. Use the `/story` skill (`.claude/skills/story/`): it reads the
  story, checks its dependencies on the tracking board, and follows the cycle below.
- Every change follows the same cycle: **contract** (signatures and PHPDoc, neutral bodies) → **red tests** →
  **implementation**. Never implement behaviour that has no test yet.
- The **Definition of Done** is `epics.md` §1. A story is not done until the tracking board (§8) says `done` and a
  **Done** note under the story records what was built and any deviation.
- A behaviour not covered by `model.md`, or contradicting it: stop and ask, then record the decision in `model.md`
  §14 (or a question in `epics.md` §6) **before** coding it.
- Every public change in `CHANGELOG.md` (`[Unreleased]`); every new rule or pattern in `docs/architecture.md`.
- `phpstan-baseline.neon` stays empty: never add an entry, fix the cause.
- One story per branch (`us/<id>-<slug>`, e.g. `us/3.4-shape-selector`), small commits, imperative English messages.

## Architecture (details in `docs/architecture.md`)

The class **declares** the head, the shape of each revision is **compiled once**, the projector **walks down**, the
upgrader **walks up**, the exporter **describes**.

- Layers, each depending only on those to its left: `Support` ← `Configuration` ← `Revisions` ← `Definition` ←
  `Conversion` ← `Schema` ← `Compilation` ← `Projection` ← `Upgrade` ← `Export` ← `Checking` ← `Laravel` ← `Testing`
  (`Exceptions` usable everywhere). Enforced by `phpat` (`tests/Architecture/ArchitectureTest.php`).
- **Pure core:** no `Illuminate\*` in any layer left of `Laravel`. Only `src/Laravel/` uses facades, global helpers
  or the container (`disallowed-calls`).
- **One pure function decides shapes:** `Compilation\ShapeSelector::at($descriptors, $revision)`. Projection, rules,
  export, check and lock all read its `Shape`; nothing else computes visibility, names or conversion plans.
- **Inclusive bounds:** `was(X)` / `until(X)` hold up to `X` included, `since(X)` from `X` included. Writing rule: a
  field changing in `N` gets `was(N->previous())`.
- Descriptors are immutable fluent builders with **lazy sources** (a key, a dotted path or a closure receiving the
  subject, never an evaluated value); compilation runs on a `Probe` and must never run a source.
- **Value pipeline** (`model.md` §6, D93): presence conditions → `when()` / `unless()` → read the source on the
  subject → normalize to the head type → down conversions on PHP values → serialize once. A field refused by a
  condition is never read. A `was()` never has a source.
- **Two closure signatures** (`model.md` D18, D93): value closures (source, `down:`, `up:`) take `($value, $subject)`,
  called through `Support\Invoke::value()`; conditions (`when()`, `unless()`) take `($subject, $attribute)`, called
  through `Support\Invoke::condition()`.
- Definitions (`src/Definition/`) are `final readonly`, serializable, closure-free: the public contract for tools.
- The current revision lives only in `RevisionScope` (ambient context, `scoped` binding in Laravel): no other static
  state but `Support\Memo`.
- Classes `final` (except `Schema\Descriptor`); traits only `IsRevision`, `RevisionedRequest`, `RevisionedResource`,
  `InteractsWithRevisions` (declared for applications); `declare(strict_types=1)`; PHPStan max.
- The check never throws on application code: it adds a diagnostic (`code`, class, field, revision). Programming
  errors in declarations throw `DefinitionException` (named constructors).

## Tests

- `tests/Unit/`: one test class per class. `tests/Feature/`: Orchestra Testbench scenarios on `tests/Fixtures/`.
  `tests/Architecture/`: phpat rules, run by PHPStan. `tests/Types/`: `assertType()`, analysed, never run.
- The **reference scenario** (`ApiRevision`: `Genesis`, `Winter25`, `Spring26`, `Atlas`; `OrderResource`;
  `StoreOrderRequest`) is the one of `model.md` §2–§7; its table in §4.2 is asserted row by row.
- Invariant test: for every fixture at every revision, projected keys = `Shape` names, and the projection validates
  against the exported JSON Schema of that revision.
- Test names are sentences (`test_a_rename_applies_up_to_its_revision_included`).

## Language

Everything in the repository is in English (code, `docs/`, commit messages). Conversations with the maintainer may be
in French.
