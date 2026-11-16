# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Descriptor macros: `Schema\DescriptorMacros::register($name, Closure)` adds a fluent method to every descriptor,
  served by `Descriptor::__call()` (`Schema::string()->whenInFields('notes')`). The closure receives the descriptor,
  then the arguments, and must return an instance of the descriptor's class; a name `Descriptor` declares is refused
  and a public method of the descriptor's own class wins. New `DefinitionException::invalidMacroName()`, `unknownMacro()`
  and `invalidMacroResult()`. PHPStan: `apiRevisions.macros` maps each name to its static method
  (`PHPStan\DescriptorMacrosExtension`), typed with the method's parameters after the descriptor and returning the
  receiver's type (`model.md` D87).
- Projection plan: `Projection\Projector::plan(Shape, descriptors, ?keys)` resolves a projection once per render into a
  `Projection\ProjectionPlan`, run per subject (`run($subject)`); `keys` keeps those head keys of the top level
  (a sparse fieldset). `Projector::project()` runs through it.
- `RevisionedResource::collection()` returns a `Laravel\Resources\RevisionedResourceCollection`: `toArray()` runs
  once per render on a template instance and every item goes through one plan (about 4.5 times faster on the
  benchmark). A resource whose structure depends on its model in plain PHP opts out with
  `static revisionedPerSubject(): bool` returning `true`; plain values and native conditions keep the per-item path.
- Benchmark: a `lookup` scenario keeps `Shapes::for()` on a cached class at one array read and one `unserialize()`
  (against the bare `unserialize()` of the same entries).
- `Checking\Declarations`: the discovered classes with their declaration kinds (`declarations()`), or those existing at
  one revision (`at()`), resolvable from the container.
- Public contract under semver from 1.0, named in `docs/architecture.md` §4: the definition model, `Shape`,
  `JsonSchemaExporter::export()` / `objects()`, the structure of `revision:export`, `Changelog::changes()` /
  `markdown()` with `Change` / `ChangeKind`, `ClassFinder` and `Declarations`.
- Documentation: the README (install, the enum, a resource, a request, resolution, check and lock in CI, retiring a
  revision, outside HTTP, testing) and the guide `docs/guide.md`, covering every feature.
- Without configured enum (`revisions.enum` left `null`), the library works on the implicit one-case
  `Revisions\Unrevisioned` (`head`): resources render, requests validate and every command succeeds, with no history;
  the `revision` middleware resolves nothing and adds no revision header, a queued job captures no revision,
  `revision:changelog` has no change to list (as for any one-case enum without `--from` / `--to`), `revision:lock`
  locks nothing, a job captured earlier runs in the head. A `default` other than `latest` / `oldest` without enum is
  refused; `Route::revisioned()` and `withRevision()` throw, naming the missing enum.
- Checks contributed by a package or an application: a `Checking\Check` tagged
  `Laravel\RevisionsServiceProvider::CHECKS` (`'revisions.checks'`) is built by the container and run after the built-in
  checks by `revision:check` and `assertRevisionsAreConsistent()`; a tagged service that is not a `Check` throws
  `ConfigurationException::notACheck()`.
- `Conversion\Converters::identity(string $name)` (and `isIdentity()`): a custom type declares, in its
  `DeclaresConversions::conversions()`, that its values do not depend on its fragment. A `was(schema:)` changing only
  its fragment then plans no conversion step (no `conversion-missing`), still shown in the export, the lock and the
  changelog; such a type is exported in place rather than as a `$defs` component, so two of its fragments in one
  revision never conflict. `Compilation\Shapes::converters()` gives the registry the shapes are planned against.
- Class lifetime: a `Revisioned` class implementing `Schema\HasLifetime` (`static revisionedLifetime(): Lifetime`,
  with the new `Lifetime::since(R)` / `Lifetime::until(R)`) exists only in those revisions; `Schema\ClassLifetime::exists()`
  tells whether it exists at a revision. Compiling it elsewhere throws `DefinitionException::classAbsent()`. The
  check, `revision:cache`, `revision:export`, the lock and `assertRendersAtEveryRevision()` skip the revisions it does
  not exist in (a lock file lists the classes existing at its revision); the changelog lists the class added and
  removed. New check code `resource-absent`: a visible `Schema::resource()` field nesting a class absent at its
  revision. A locked revision holding a class now absent there is a `lock-mismatch`.
- A class implementing `Schema\Revisioned` itself, without the Laravel traits, is a supported path (compiled, cached,
  checked, locked, exported); `Revisioned::describeRevisioned()` is no longer `@internal`, and may build the
  declaration on the implementer's own stand-in instead of the `Probe`.
- Agent skill `skills/laravel-api-revisions/`, shipped with the package: `SKILL.md` (the rules that prevent most
  mistakes, such as the writing rule of the bounds and the value a `down:` closure receives), `reference.md` (every
  public entry point) and `recipes.md` (tested examples of the common tasks).
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
- Shapes: `Compilation\ShapeSelector::at($head, $revision, $direction, $converters)`, the one function giving the
  `Compilation\Shape` of a revision (`names()`, `field()`, the fields added later for a request, the name
  `Compilation\Collision`s): per visible field a `Compilation\FieldDefinition` (name, type, nullable, required,
  sometimes, conditional, default and lifetime at the revision, its `Compilation\FieldVersion` at every applicable
  `was()`, its unbound conversion chain in the direction of the shape, the items of a list, the children of an object as
  a nested `Shape`), every omitted `was()` argument inherited from the next newer shape. Closure-free and serializable.
- Compilation: `Schema\Revisioned` (static `declarationKinds()` and the `@internal` bridge
  `describeRevisioned(DeclarationKind, $subject)`: the probe at compilation, the value of a nested resource at
  projection, model.md D50), `Schema\DeclarationKind` (`Response`, `RequestBody`,
  `RequestQuery`; `direction()`), `Compilation\Probe` (throws `DefinitionException::subjectReadAtCompilation()` on any
  access), `Compilation\Compiler::compile($class, $kind, $revision)` (configured descriptors, the shape of the
  revision naming its class and kind: `Shape::$class`, `Shape::$kind`); `DefinitionException::notRevisioned()`,
  `undeclaredKind()`, `invalidDeclaration()`. `Descriptor::configured()` configures a descriptor and the schemas of its
  `was()`; composites configure their parts through it.
- Projection: `Projection\Projector::project(Shape, $descriptors, $subject)` (built with the `Configuration`, the
  `Converters` and a `Projection\SourceReader`) renders the visible fields of a shape under their name at its
  revision: presence conditions, source (a renamed field reads its head key), normalization, `when()` (kept on `true`),
  down chain bound to the fresh descriptors, serialization by the type in force at the revision; `null` skips
  normalization and conversions. `SourceReader::read()` (keys and dotted paths on arrays, `ArrayAccess`, public
  properties, `__get()` without `isset()`), `has()`, `peek()` (no side effect). `Exceptions\ValueException`
  (`refused()`, `nullNotAllowed()`, `unconvertible()`; `class`, `field`, `revision`; never the value);
  `DefinitionException::missingDescriptor()`.
- Upgrade: `Upgrade\Upgrader::upgrade($shape, $descriptors, $input, ?$hook)` takes a validated input of a request
  shape's revision to the head, step by step: renames and `up` conversions of each `was()` read from a snapshot of the
  step (values normalized to the type of the step first), the `Upgrade\UpgradesInput` hook, the defaults of the fields
  added in the newer revision (never over an explicit `null`), the fields retired dropped; converted and defaulted
  values serialized back to raw values, the result in the head order (model.md D26, D54); a response shape throws
  `DefinitionException::notAnUpShape()`. `FieldVersion::$schemaChange` and `FieldDefinition::nameAt()` give the
  descriptor in force and the name at a revision. Objects, lists and nested resources are upgraded too, in the same
  step as their parent (model.md D55); the `Upgrader` takes an optional `Shapes` for the nested resources.
- Laravel: `config/revisions.php` (publish with `--tag=revisions-config`), validated lazily by
  `Laravel\RevisionsConfig` (`Exceptions\ConfigurationException`); the service provider binds the core
  `Configuration`, one `Shapes`, the `RevisionScope` of each request (`scoped`, its base the configured fixed default
  outside a request) and the `ValueErrorHandler` (`strict_values`, `null` following `app.debug`; lenient errors
  reported once per class and field and request).
- Resolvers: the `Laravel\Http\RevisionResolver` contract (`resolve(Request): ?Revision`), `HeaderResolver`
  (`name`, `Api-Revision`), `QueryResolver` (`name`, `revision`) and `PathResolver` (`parameter`, `revision`) in
  `Laravel\Http\Resolvers`, chained by `ResolverChain` (a `scoped` binding built from `revisions.resolvers`, each
  resolver made by the container with its options as named arguments; the first revision wins, then `default`: a fixed
  revision, or a resolver class whose `null` gives the head). A value naming no revision throws
  `Exceptions\UnknownRevisionException` (the value and the accepted ones). `RevisionsConfig` refuses a resolver or a
  class `default` not implementing `RevisionResolver`; `resolvers` defaults to the header resolver.
- Middleware `Laravel\Http\ResolveRevision` (alias `revision`): resolves the revision of the request through the
  `ResolverChain`, makes it the request's current revision, and adds the `response_header`, `Vary` on the revision
  headers, `Deprecation` (RFC 9745) and `Sunset` (RFC 8594) from the revision's `#[Meta]`; an unknown revision is
  answered 400 with `{message, revision, accepted}`. `UnknownRevisionException` cuts the value it echoes to 64
  characters. A resolver reading request headers implements the optional `Laravel\Http\VariesOn` contract
  (`headers()`, implemented by `HeaderResolver`): `ResolverChain::headers()` collects them for `Vary`.
- Path mode: the route macro `Route::revisioned(fn (Router $router) => …)` (`Laravel\Routing\RevisionedRoutes`)
  prefixes its routes with `{revision}` (the `parameter` of the configured `PathResolver`, required:
  `ConfigurationException::pathModeWithoutResolver()`), constrained to the revision values, under the `revision`
  middleware; the middleware forgets the parameter and sets it as a URL default, so controllers never receive it and
  generated URLs keep the revision. `PathResolver::parameter()`, `ResolverChain::parameters()`,
  `RevisionsConfig::pathParameter()`. `extension.neon` declares the macro to PHPStan (`PHPStan\RevisionedRouteExtension`;
  `PHPStan\CustomTypeMethod` becomes `PHPStan\DeclaredMethod`).
- `Laravel\Resources\RevisionedResource`, the trait of a revisioned `JsonResource`: `toArray()` returns descriptors,
  `resolve()` projects them on the model at the current revision, in the order of `toArray()`; `with()`,
  `additional()`, wrapping, collections and pagination unchanged; implements the `Revisioned` bridge (a response).
  A plain value is rendered as it is (a nullable `any` in the shape: `Schema\EagerSchema`); a native `when()`,
  `whenLoaded()` or `mergeWhen()` is honoured at run time and kept out of the shape; `Arrayable` values are rendered as
  arrays; lenient value errors are reported. The `Projector` is bound in the container (`scoped`).
- `Descriptor::whenLoaded('relation')` (dotted for a nested relation, `Schema\Presence::loaded()`): the field is kept
  only when the relation is loaded, checked before the source; on a subject without relations, the key is present.
- `Projection\SourceReader` is now an interface (`read()`, `has()`, `peek()`, `loaded()`), implemented by
  `Projection\PlainSourceReader` (the former class) and decorated for Eloquent by
  `Laravel\Resources\EloquentSourceReader` (attributes present in `getAttributes()`, relations loaded, never a lazy
  load).
- Validation rules of a request shape: `Laravel\Validation\RuleCompiler::compile($shape, $descriptors)` (bound in the
  container) gives the Laravel rules under the names of the client's revision (dotted for children and nested
  resources, `.*` for items): `sometimes` / `required` / `nullable` as declared, the rules of the type (one
  `Laravel\Validation\RuleMapper` per kind, replaceable: string length, pattern and known formats, `integer` /
  `numeric`, `boolean`, `date_format:`, Laravel's `Enum`, `list` / `array`), those of the first up conversion
  (`numeric`, `integer`, the values of an enum), then the descriptor's `rules()` as given. A required child of a parent
  the client may leave out gets `required_unless:<parent>,null`. `DefinitionException::rulesOfAResponse()`,
  `unknownKind()` (a mapper keyed by no kind).
- `Laravel\Requests\RevisionedRequest`, the trait of a revisioned `FormRequest`: the head body in `body()`, the head
  query parameters in `queryParameters()`, the `protected upgrade()` hook on the body; `rules()` validates the input
  in the client's revision under its names, then the input is upgraded to the head, which `validated()` / `safe()`
  return (`input()` / `all()` keep the input as sent). A name declared in both the body and the query at a revision
  throws `DefinitionException::declaredTwice()`. The `Upgrader` is bound in the container (singleton).
- A value the upgrade rejects is a validation error on the client's key: the upgrader throws
  `Exceptions\InvalidInputException` (`key`, `reason`) for a `ConversionException` or a `ValueException` (instead of
  the exception itself); an `up:` closure rejects an input with `ConversionException::invalid($message)`, its message
  being the error; any other rejection gets Laravel's `validation.in` message. `ConversionException::missing()` stays
  a declaration error (`ConversionException::$declarationError`), as do the other exceptions of a closure (model.md
  D63).
- Queued jobs outside HTTP: a job implementing the marker interface `Laravel\Queue\CapturesRevision` runs in the
  revision current when it was dispatched (`handle()`, its middleware and `failed()` inside `using()`, the `sync` queue
  included); the payload stores the backed value under `ark4ne:revision`. A revision the enum no longer knows fails the
  attempt with `UnknownRevisionException`, and `failed()` runs in the base revision. Other jobs run in the base
  revision (model.md D64).
- Compiled shapes: `php artisan revision:cache` (run by `php artisan optimize`) compiles every declaration kind of every
  class implementing `Revisioned` found under `discovery.paths` (plus `discovery.classes`) at every revision, and
  writes `cache_path` (`bootstrap/cache/revisions.php`); `revision:clear` (run by `optimize:clear`) removes it.
  `Compilation\CompiledShapes` reads and writes the file (`load()`, `write()`, `get()`; one serialized shape per
  `class@kind@revision`, unserialized on first use), `Shapes` takes it as its second argument and reads it before
  compiling; `Laravel\Discovery\PathClassFinder` finds the classes; `Exceptions\CacheException`. Changing the date
  formats, a declaration or a custom type requires `revision:cache` again, as for `route:cache`.
- Benchmark gate: `composer benchmark` (`bin/benchmark.php`, reference `bin/benchmark/reference.json`, `--update`)
  compares the projection at the head and at the oldest revision with a plain `JsonResource` (time and memory ratios,
  10 % tolerance); run in CI. A projection costs about ×19 the time of the plain resource: `TypeRegistry::converters()`
  without argument is built once until the next `register()` or `flush()`, the projector configures only the
  serializer's own defaults (`configure()`), and a `was()` inserts its change without sorting the history.
- Value errors: `Projection\ValueErrorHandler` with `StrictValues` (throws) and `LenientValues` (an optional reporter;
  renders the cast of a refused value as it is, `null` for a failed conversion or a non-nullable `null`, model.md D53), given to
  the `Projector` or chosen from `Configuration::$strictValues` (`true` by default); a non-nullable field or item
  resolving `null` is a value error.
- Nested projection: objects project their head children on their value (or the parent's subject), lists each item
  (an item whose condition fails is dropped, model.md D51), a `Schema::resource()` the shape of its class at the
  same revision with its descriptors built on the value (`Projector` takes an optional `Shapes`); the composite's
  `when()` / `down:` receive the projected children. `FieldDefinition::$children` / `$items` are those of the head
  composite at `FieldDefinition::$headAt`, the revision after its newest `was(schema:)` (model.md D52).
- Shapes repository: `Compilation\Shapes` (built with a `Compiler`, the default one by default), `for($class, $kind,
  $revision)` compiling each `(class, declaration kind, revision)` once, `flush()`.
- Nested resources: `Schema::resource(class-string<Revisioned>, $source)` (`Schema\ResourceSchema`, `class()`;
  `DefinitionException::notRevisioned()` for another class): an `Object` kind referencing the class
  (`TypeDefinition::$resource`, `withResource()`), never a copy of its shape, so cycles compile. The nested shape is
  the class's declaration of the same kind at the same revision (model.md D49): the compiler refuses a nested class
  that does not declare it (`undeclaredKind()`). Two types referencing different classes hold other values:
  changing the referenced class needs a converter (a missing step otherwise).
- Conversions of custom types: a custom type implementing `Schema\DeclaresConversions` registers its conversions
  (`conversions(Converters): Converters`); `TypeRegistry::converters()` gives the built-in registry with those of every
  registered type, to give to `ShapeSelector::at()`. `Converters::register()` takes a custom type's name as a
  side (`Kind::Custom` and an empty name throw `DefinitionException::invalidConversionSide()`); `Converters::keyOf()` keys a custom type by its name (`custom(money)>float:down`), so two custom types never
  overwrite each other.
- Defaults: `Configuration::$missingDefault` (`Configuration\MissingDefault::Error`, the default, or `Null`); with
  `Null`, a `required()->since(R)` field declared without `default:` gets `null` as default in the shapes
  (`ShapeSelector::at(..., configuration:)`), an optional one keeps none.
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
- Check foundations: `Support\Diagnostic` (`code`, `severity`, `class`, `field`, `revision` as its backed value,
  `message`, `isError()`), `Support\Severity` (`Error`, `Warning`) and `Support\Diagnostics` (collected in order,
  `errors()`, `warnings()`, `hasErrors()`, countable and iterable); `Checking\Check` (one rule, run once on the revision
  enum then once per declaring class), `Checking\CheckContext` (the target, its shapes, `error()` / `warning()`),
  `Checking\Checker` (runs the checks with a fresh `Shapes` per run) and the `Checking\ClassFinder` contract,
  implemented by `Laravel\Discovery\PathClassFinder`; `DefinitionException::noShapeForRevisions()` when a check asks a
  shape of the enum, `foreignRevision()` for a diagnostic on a revision of another enum.
- Check rules (model.md §9): `Checking\Checker::builtIn()` — `Checks\OrderMissingCase`, `OrderDuplicateCase`,
  `BoundRepeated` (warning), `HistoryBeforeSince`, `HistoryAfterUntil`, `ConversionMissing`, `ConversionLossy`
  (warning), `NameCollision` (also a name in both the body and the query of a request), `RequiredSinceWithoutDefault`
  (none under `missing_default: null`), `EagerValue`; `Laravel\Checking\NativeCondition` (warning). A
  `DefinitionException` of a descriptor in a `RevisionedResource::toArray()` is no longer wrapped in `failedDescribing()`. Before its rules,
  the checker builds each class (descriptors, shapes at every revision): anything thrown is one error under the code
  of its `DefinitionException` (`DefinitionException::$checkCode`: `lifetime-inverted`, `since-older-than-group`,
  `until-newer-than-group`, `history-unordered`, `unknown-resource`), else `definition-error`, and
  the check goes on (D39, D66); an invalid `order()` stops it after the enum. `Compiler::describe()` (the configured
  head descriptors) and `Compiler::converters()`; `CheckContext::order()`, `kinds()`, `descriptors()`, `converters()`.
  `schema-override` comes with the export (US 10.1, D65).
- `php artisan revision:check` (`Laravel\Console\CheckCommand`): runs `Checker::builtIn()` plus `NativeCondition` on the
  enum and every class of `discovery.paths` / `discovery.classes`, with the application's configuration; a table of
  diagnostics (`--format=json` for CI tools), fails on an error, warnings alone pass; `--list` prints the discovered
  classes. The container binds `Checking\ClassFinder` (`PathClassFinder` over the discovery, also used by
  `revision:cache`) and `Checking\Checker`. `Support\Diagnostic::toArray()`.
- `Testing\InteractsWithRevisions` for an application's `TestCase`: `assertRevisionsAreConsistent()` fails with every
  diagnostic when one is an error.
- JSON Schema export (model.md §11, D67): `Export\JsonSchemaExporter` (draft 2020-12; `export($class, $kind,
  $revision)`, `visit(Shape)`, `typeKeywords()`), implementing the `Export\SchemaVisitor` contract: one document per
  class, kind and revision, its revision `meta()` in `$comment`; types and constraints, enum values, nullable (`null`
  in `type` / `enum`, or `anyOf`), `required` (a response field unless conditional, a request field `required()`
  without `sometimes()`), descriptions, examples, `deprecated`, custom fragments, dates in their wire format (a
  `pattern` for a numeric PHP format), the `DescribesSchema` keywords last (D36); nested resources in `$defs`
  (`$ref`, cycles included); objects open. `php artisan revision:export {--revision=} {--class=}` prints it as JSON.
- A `pattern()` is exported without its delimiters when its flags are `u` / `D` only, otherwise described in `$comment`
  (D68). `Definition\TypeDefinition::$nullable` (`withNullable()`): the nullability of the items and children of a
  composite. The compiled-shapes cache format is now `2`: run `php artisan revision:cache` again after upgrading.
- Check rule `schema-override` (warning, `Checking\Checks\SchemaOverride`, in `Checker::builtIn()`): a rule keyword
  overriding a keyword of its field's type.
- Lock of the published revisions (model.md §9, D11, D69): `Checking\Lock` (one `<value>.json` per locked revision
  under `lock_path`: the structure of every class and kind, documentation left out, keys sorted, and the revision
  before it), `Checking\LockedRevision`, `Exceptions\LockException`. `php artisan revision:lock` writes the revisions
  but the head that have no lock yet; `--revision=` (re)locks one, the head included. Check rules `lock-mismatch`
  (per field, keyword, class or kind; a class or kind added only when it has fields there) and `lock-order-changed` (`Checking\Checks\LockMismatch`,
  `LockOrderChanged`), added by the Laravel binding of the `Checker` over the configured `lock_path`.
- Components (model.md §11, D70): `Schema\ObjectSchema::component($name)` names an object, a custom type is one under
  its `TypeDefinition::custom()` name; each is defined once in the `$defs` of a document (`Export\Components`) and
  referenced `#/$defs/<name>` from every use, its body at the revision exported (so its history applies everywhere).
  `Definition\TypeDefinition::$component` (`withComponent()`); the compiled-shapes cache format is now `3` (run
  `php artisan revision:cache` again). Two bodies under one name throw `DefinitionException::componentConflict()`
  (`DefinitionException::$field`) and are reported by the check rule `component-conflict` (error,
  `Checking\Checks\ComponentConflict`, in `Checker::builtIn()`). A custom type is now a `$ref` in the export instead
  of its fragment inline.
- Changelog (model.md §11, D71): `Export\Changelog` (`changes($class, $kind, $revision)`: what a revision changes
  against the one before it, fields matched by their head key path through object children and list items;
  `markdown($classes, $from, $to)`: newest first, each revision headed by its `meta()`), `Export\Change`,
  `Export\ChangeKind` (added, removed, renamed, type changed). `php artisan revision:changelog {--from=} {--to=}`
  prints it.
- Test helpers (model.md D28, D72): on `Testing\InteractsWithRevisions`, `withRevision(Revision)` (a header, the route
  parameter of path mode or the query, as the first resolver of the chain reads it) and
  `assertRendersAtEveryRevision($class, $subject, ?$revisions)` (a head fixture projected at each revision and validated);
  the `TestResponse` macro `assertMatchesRevisionSchema($class, ?Revision $revision)` (registered by the trait's
  `setUpInteractsWithRevisions()`; the revision of the response header by default, the `data` of a wrapped body, a
  list item by item) — `Testing\TestResponseMacros`, `Testing\RevisionSchema`. They validate against the export with
  closed objects: `JsonSchemaExporter` takes `closed: true`. `opis/json-schema` is suggested (needed by these
  assertions). The PHPStan extension declares the macro (`PHPStan\RevisionSchemaAssertionExtension`).
- `HeaderResolver::DEFAULT_NAME` (`Api-Revision`) and `QueryResolver::DEFAULT_NAME` (`revision`): the names they read
  when the `name` option is not set.
- Prune (model.md D73): `Checking\Prune` (`run($classes)`: a `Checking\PruneReport` of the `Checking\PruneCandidate`s
  — `until(P)`, `was(P)`, `since(P)` of the oldest revision `P`, `Checking\PrunedDeclaration` — and the classes that
  did not build), `Checking\DeclarationLocator` (the file and line of a field key, by reflection). `php artisan
  revision:prune` lists them, relative to the application, and always succeeds.
- `Export\JsonSchemaExporter::objects()`: a document ready for `json_encode()`. Fixed: `revision:export` wrote an
  `any` field as `[]` instead of `{}`.
- `Checking\CheckContext::$classes` (every class of the run), `revision()` (a case by its backed value) and `export()`
  (the JSON Schema of the checked class from the shapes of the run).

### Changed

- `Projection\PlainSourceReader` no longer calls `get_object_vars()` on every read: an initialized public property
  is read directly (the properties of a class listed once by reflection), a dynamic one found with
  `property_exists()`; `get_object_vars()` decides only the rare cases (uninitialized, lazy, non-public, internal
  classes). Same outputs; an Eloquent model no longer keeps a property table per instance read (`model.md` D88).
- A descriptor that does not match its shape (missing, or a composite on one side only) throws
  `DefinitionException::missingDescriptor()` when the projection is planned, even when its value is `null`; it threw
  only on a non-null value.
- `CompiledShapes::load()` no longer walks the compiled file: an entry is checked when first read, and an invalid one
  (`null` included) throws `CacheException::invalidFile()` then; a cached shape loads neither its declaring class nor
  a descriptor.
