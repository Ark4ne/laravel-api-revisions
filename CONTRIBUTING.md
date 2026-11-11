# Contributing

Thank you for contributing to `ark4ne/laravel-api-revisions`.

## Before writing code

Read, in this order:

1. [`docs/specs/model.md`](docs/specs/model.md): the behaviour of the library and its decisions.
2. [`docs/architecture.md`](docs/architecture.md): the layers, the patterns and the conventions.
3. [`docs/specs/epics.md`](docs/specs/epics.md): the user stories, the Definition of Done and the tracking board.

A deviation is discussed in an issue and recorded in those documents before it is coded.

## Requirements

- PHP 8.3 or later.
- Composer.

```bash
composer install
```

## Workflow

1. **Contract.** Write the signatures and their PHPDoc first, with neutral bodies.
2. **Red tests.** Write the tests that describe the expected behaviour; they fail for the right reason.
3. **Implementation.** Make them pass.

Never implement behaviour that has no test yet. Bug fixes start with a test that reproduces the bug.

## Checks

Run every blocking check locally before opening a pull request:

```bash
composer ci              # composer.json, code style, PHPStan (max, architecture rules), tests
composer test:coverage   # at least 90% of the lines
```

On GitHub, the CI runs on pull requests (open a draft one to run it on a story branch), on `main` and on release
branches. Mutation testing is blocking on `main` only.

## Conventions

- Everything is in English: code, documentation, commit messages.
- Commit messages are imperative and name the story when there is one (`Add the ShapeSelector (US 3.4)`).
- Every public change is recorded in `CHANGELOG.md` under `[Unreleased]`; every breaking change also in `UPGRADE.md`.
