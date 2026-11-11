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
- `Schema::rule(array $rules, $source)` (`RuleSchema`, the escape hatch: kind `Any`, any value rendered as it is) and
  `Schema\DescribesSchema`: a rule object implementing it adds its JSON Schema keywords to the type of the field it is
  declared on (`TypeDefinition::$jsonSchema`), whatever the descriptor; non JSON-ready keywords throw
  `DefinitionException::invalidJsonSchema()`.
- Conditions on every descriptor: `when(Closure $condition)` (called `($value, $subject)` after normalization) and
  `whenHas(?string $key)` (a `Schema\Presence`, checked before the source; `DefinitionException::invalidPresenceKey()`);
  `isConditional()`, `conditions()`, `presences()`.
- Lifetime of a field: `Descriptor::since(Revision, $default)` (from the revision included; a default for older
  clients, `Support\Undefined` when none) and `until(Revision)` (up to the revision included), read as
  `Definition\Lifetime` (`covers(Revision)`); a `since` newer than the `until` throws
  `DefinitionException::invertedLifetime()`; a bound set twice keeps the last call and is recorded
  (`Descriptor::repeatedBounds()`).
- Groups: `Schema::since(Revision, array $fields, $default)` and `Schema::until(Revision, array $fields)` return the
  array with each member bounded, to spread with its string keys (in a declaration or in `Schema::object()`); a
  member's own narrower bound and own default win, a wider one throws `DefinitionException::sinceOlderThanGroup()` /
  `untilNewerThanGroup()`; a group never records a repeated bound.
- Conversions: `Definition\Direction` (`Down`, `Up`), the `Conversion\Converter` strategy (`convert()`, `lossless()`,
  `extraRules()`) and the `Conversion\Converters` registry keyed by `(from Kind, to Kind, Direction)`
  (`builtIn()`, `register()` with a factory receiving both types, `find()`, `key()`), with the built-ins of model.md
  §5 in both directions (`Conversion\BuiltIn\*`: int ↔ float / string / bool, float → string / int, bool → string /
  int, string → bool, enum ↔ backed value, D40, D41); `Exceptions\ConversionException::invalidEnumValue()`.
  `Converter::convert($value, $subject = null)`: only a closure reads the subject.
- Explicit conversions: the `Conversion\ExplicitConverter` marker (never warned `conversion-lossy`),
  `Conversion\ClosureConverter` (a `down:` / `up:` closure `($value, $subject)`) and `Conversion\Cast::round()`,
  `floor()`, `ceil()` (float → int, a `Conversion\Rounding`). A float → int conversion (`FloatToInt` included) of
  a float outside the int range throws `ConversionException::outOfIntRange()` (model.md D44).
- Custom types: `Schema\TypeRegistry` (`register($name, MoneySchema::class)` at boot, `has()`, `make()`, `flush()`;
  static like Laravel's macros, model.md D42) served by `Schema::__callStatic()` (`Schema::money('total')`);
  `TypeDefinition::custom($name, $fragment)` (`Kind::Custom`, the name the type declares, its JSON Schema fragment in
  `fragment`, apart from the rule keywords of `jsonSchema`); `DefinitionException::unknownType()`, `invalidTypeName()`,
  `invalidCustomType()`, `invalidTypeFragment()`.
- PHPStan extension (`extension.neon`, registered for `phpstan/extension-installer`): the custom types mapped in
  `apiRevisions.types` are static methods of `Schema` typed with their class and their constructor's parameters
  (`PHPStan\CustomTypesExtension`).
- Conversion chains: `Conversion\ConversionChain` (`empty()`, `withStep()`, `bind()`, `apply($value, $subject)`,
  `stepOf()`, `missing()`, `isEmpty()`), the steps of one field at one revision in one direction, planned from types and
  references only (`Conversion\ConversionStep`, `Conversion\StepKind`: registered, explicit, missing) so it
  serializes; two types holding the same values add no step; `ConversionException::missing()` when a step without
  converter is applied.
- History: `Descriptor::was(Revision, name:, schema:, down:, up:)` (`down:` / `up:` a closure, kept as a
  `ClosureConverter`, or a `Cast`) adds a `Schema\Change` to the field's
  `Schema\History` (its lifetime and its changes, newest first whatever the call order; `Descriptor::history()`);
  no effect on the head type. `DefinitionException::invalidHistory()` (a `was()` on the head, twice on a revision),
  `emptyHistoryName()`, `emptyChange()` (a `was()` changing nothing) and `sourceInHistory()` (a `schema:`, its items
  or children with a source, a lifetime, a history, a default or a condition).
- `Configuration\Configuration` (`dateFormat`, `dateTimeFormat`), given to descriptors by `Descriptor::configure()`.
- `Definition\TypeDefinition` and `Definition\Kind`, the type of a field as read by the tools.
- `Revisions\RevisionOrder`: `index()`, `compare()`, `previous()`, `next()`, `latest()`, `oldest()`, `between()`.
- `Exceptions\RevisionsException`, marker of the package exceptions, and `Exceptions\DefinitionException`
  (`mixedRevisions()` when revisions of two enums are compared, `foreignRevision()` when a revision of another enum
  is used or current, `invalidOrder()` when `order()` misses a case, lists one twice, lists a foreign case, is not a
  list or is empty).
