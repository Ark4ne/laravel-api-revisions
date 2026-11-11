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
- `#[Revisions\Meta]` on a revision case (`description`, `publishedAt`, `deprecatedAt`, `sunsetAt` as `Y-m-d` days,
  checked when read), `Revision::meta()` (an empty `Meta` when absent), `Meta::isDeprecated()` / `isSunset()` from
  their day included; `DefinitionException::invalidMetaDate()` / `invalidMeta()`.
- Current revision: `Revision::current()` (the innermost `using()`, else the revision of the context, else the head)
  and `Revision::using($revision, $callback)` (restores the previous revision, even when the callback throws; render inside the callback);
  `Revisions\RevisionScope`, the only holder of the current revision, found through `RevisionScope::provider()`.
- Schema vocabulary: `Schema\Schema::string()`, `int()`, `float()`, `bool()` returning `StringSchema` (`min()`,
  `max()`, `format()`, `pattern()`), `IntSchema` / `FloatSchema` (`min()`, `max()`), `BoolSchema`; every descriptor
  (`Schema\Descriptor`, the extension base) has a lazy source (the declared key, a key or dotted path, or a closure
  `($value, $subject)`; an evaluated value throws `DefinitionException::eagerSource()`), `nullable()`, `required()`,
  `sometimes()`, `describe()`, `example()`, `deprecated()`, `rules()`, and returns a new instance on every modifier.
- `normalize()` / `serialize()` of the scalars (model.md §6.3); a refused value is returned as a `Schema\InvalidValue`
  carrying its lenient cast. An evaluated source is refused even in a file without `strict_types`.
- `Schema::date()` / `dateTime()` (`DateSchema` / `DateTimeSchema`, `format()`, else the configured default: `Y-m-d`
  and RFC 3339) and `Schema::enum(BackedEnum::class)` (`EnumSchema`: the case or its backed value, serialized as the
  backed value; its type exports the enum, its backing kind and its values).
- `Schema::list(Descriptor $items, $source)` (`ListSchema`, `min()` / `max()` on the size; any `iterable` normalized
  to a list) and `Schema::object(array<string, Descriptor> $children, $source)` (`ObjectSchema`, its value is the
  subject of its children; serialized as a JSON object even when empty), nested freely; `configure()` reaches items
  and children; the children are projected before the composite's own conversions (model.md D34). An object without
  source reads its children on the parent's subject (`Schema\Source::parent()`). `TypeDefinition` exports `items` and
  `children`.
- `Configuration\Configuration` (`dateFormat`, `dateTimeFormat`), given to descriptors by `Descriptor::configure()`.
- `Definition\TypeDefinition` and `Definition\Kind`, the type of a field as read by the tools.
- `Revisions\RevisionOrder`: `index()`, `compare()`, `previous()`, `next()`, `latest()`, `oldest()`, `between()`.
- `Exceptions\RevisionsException`, marker of the package exceptions, and `Exceptions\DefinitionException`
  (`mixedRevisions()` when revisions of two enums are compared, `foreignRevision()` when a revision of another enum
  is used or current, `invalidOrder()` when `order()` misses a case, lists one twice, lists a foreign case, is not a
  list or is empty).
