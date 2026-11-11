---
name: story
description: Implement one user story of docs/specs/epics.md (e.g. "/story 3.4" or "/story next") following the contract → red tests → implementation cycle, the Definition of Done and the tracking board. Use whenever work starts, resumes or finishes on a story of ark4ne/laravel-api-revisions.
---

# Implement a user story

Argument: a story id (`3.4`, `US 3.4`), `M0`, or `next` (the first `todo` story of the tracking board whose
dependencies are all `done`).

## 1. Prepare

1. Read `docs/specs/epics.md`: §1 (Definition of Done), the story itself, and §8 (tracking board).
2. Check every dependency of the story is `done` on the board. If not, stop and say which one blocks it.
3. Read the sections of `docs/specs/model.md` and `docs/architecture.md` the story touches (layer, patterns,
   conventions). Never rely on memory of them.
4. If the repository is a git repository: create the branch `us/<id>-<slug>` from the default branch.
5. Set the story to `doing` on the board.

## 2. Contract

Write the public surface first: classes, interfaces, signatures, PHPDoc (generics included), neutral bodies
(`throw new \LogicException('Not implemented')` or a neutral return). Put each class in the layer
`docs/architecture.md` §2 assigns it. Run `composer stan`: the contract must be green before tests are written.

## 3. Red tests

Write the tests listed in the story (and one per acceptance criterion not covered by them). Names are sentences.
Run them: they must **fail for the right reason** (not a fatal error, not a typo). Show the failing output.

## 4. Implementation

Implement until the tests pass. Smallest change that respects the architecture; no behaviour without a test. If a
case appears that `model.md` does not decide, **stop and ask the maintainer**, then record the decision in
`model.md` §14 (or a question in `epics.md` §6) before coding it.

## 5. Done

1. `composer ci` green, `composer test:coverage` ≥ 90 %. Run Infection on the new classes when available.
2. `CHANGELOG.md` `[Unreleased]` for every public change; `docs/architecture.md` / `model.md` if a rule or decision
   changed.
3. Ask the `architecture-reviewer` agent to review the diff; address its findings or record why not.
4. Board: status `done`. Under the story, a **Done** note: what was built (main classes), deviations from the story,
   follow-ups (as new questions in §6 or new stories appended to their epic — never renumber).
5. Commit with an imperative English message naming the story (`Add the ShapeSelector (US 3.4)`). Do not push, tag
   or open a PR unless asked.

Report to the maintainer in French: what was done, test results, deviations, the next story available.
