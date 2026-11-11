# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Package skeleton: `Ark4ne\ApiRevisions\Laravel\RevisionsServiceProvider`, auto-discovered by Laravel.
- Revisions: the `Revisions\Revision` contract and the `Revisions\IsRevision` trait for an application's revision enum
  (`isBefore()`, `isAfter()`, `isAtLeast()`, `isAtMost()`, `previous()`, `next()`, `latest()`, `oldest()`), ordered by
  its explicit `order()`, never by declaration or backed value; string- and int-backed enums.
- `Revisions\RevisionOrder`: `index()`, `compare()`, `previous()`, `next()`, `latest()`, `oldest()`, `between()`.
- `Exceptions\RevisionsException`, marker of the package exceptions, and `Exceptions\DefinitionException`
  (`mixedRevisions()` when two enums are compared, `invalidOrder()` when `order()` misses a case, lists one twice,
  lists a foreign case, is not a list or is empty).
