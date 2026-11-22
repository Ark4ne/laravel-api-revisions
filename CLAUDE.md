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

1. **`docs/guide.md`** — the behaviour (what the library does).
2. **`docs/architecture.md`** — layers, patterns and conventions (how the code is organised); its §4 is the public
   contract for tools.

`ark4ne/laravel-json-api` 3.0 and `ark4ne/laravel-openapi-generator` depend on this library, never the reverse.

Targets: PHP `^8.3`, Laravel `^12.46 || ^13.0`. Only APIs present in every supported Laravel version.

## Commands

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

- Every change follows the same cycle: **contract** (signatures and PHPDoc, neutral bodies) → **red tests** →
  **implementation**. Never implement behaviour that has no test yet.
- A behaviour the guide does not describe, or contradicting it: stop and ask the maintainer **before** coding it.
- Every public change in `CHANGELOG.md` (`[Unreleased]`, breaking ones in `UPGRADE.md` too); every new rule or pattern
  in `docs/architecture.md`; every public type or method in `skills/laravel-api-revisions/reference.md` (`SkillTest`).
- Every public change updates `docs/site/` (the portal documentation) in the same change; `pnpm check
  ../laravel-api-revisions` in the docs portal (`ark4ne/docs`) must pass.
- `phpstan-baseline.neon` stays empty: never add an entry, fix the cause.
- One change per branch, small commits, imperative English messages.

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
- **Value pipeline:** presence conditions → `when()` / `unless()` → read the source on the subject → normalize to the
  head type → down conversions on PHP values → serialize once. A field refused by a condition is never read. A
  `was()` never has a source.
- **Two closure signatures:** value closures (source, `down:`, `up:`) take `($value, $subject)`, called through
  `Support\Invoke::value()`; conditions (`when()`, `unless()`) take `($subject, $attribute)`, called through
  `Support\Invoke::condition()`.
- Definitions (`src/Definition/`) are `final readonly`, serializable, closure-free: the public contract for tools.
- The current revision lives only in `RevisionScope` (ambient context, `scoped` binding in Laravel). Other static
  state: `Support\Memo` and the boot registries `TypeRegistry` and `DescriptorMacros`, never volatile data.
- Classes `final` (except `Schema\Descriptor`); traits only `IsRevision`, `RevisionedRequest`, `RevisionedResource`,
  `InteractsWithRevisions` (declared for applications); `declare(strict_types=1)`; PHPStan max.
- The check never throws on application code: it adds a diagnostic (`code`, class, field, revision). Programming
  errors in declarations throw `DefinitionException` (named constructors).

## Tests

- `tests/Unit/`: one test class per class. `tests/Feature/`: Orchestra Testbench scenarios on `tests/Fixtures/`.
  `tests/Architecture/`: phpat rules, run by PHPStan. `tests/Types/`: `assertType()`, analysed, never run.
- The **reference scenario** (`ApiRevision`: `Genesis`, `Winter25`, `Spring26`, `Atlas`; `OrderResource`;
  `StoreOrderRequest`) lives in `tests/Fixtures/`; its field table is asserted row by row.
- Invariant test: for every fixture at every revision, projected keys = `Shape` names, and the projection validates
  against the exported JSON Schema of that revision.
- Test names are sentences (`test_a_rename_applies_up_to_its_revision_included`).

## Language

Everything in the repository is in English (code, `docs/`, commit messages). Conversations with the maintainer may be
in French.
