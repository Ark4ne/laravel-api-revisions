# Reference

Every public entry point of `ark4ne/laravel-api-revisions`, grouped by task. Namespaces are given in full once per
section; `Ark4ne\ApiRevisions\` is the root of every class. Rules: [SKILL.md](SKILL.md); examples:
[recipes.md](recipes.md).

## Revisions

`Ark4ne\ApiRevisions\Revisions\Revision` is the interface of the application's backed enum (string or int);
`Ark4ne\ApiRevisions\Revisions\IsRevision` implements it but `order()`. Cases are compared by their place in `order()`,
never by declaration order.

| Method | Returns |
| --- | --- |
| `static order(): array` | every case, oldest first; the last is the head (written by the application) |
| `isBefore(R)`, `isAfter(R)`, `isAtLeast(R)`, `isAtMost(R)` | comparisons in `order()` |
| `previous()`, `next()` | the neighbour in `order()`, `null` past the ends |
| `static latest()`, `static oldest()` | the head, the oldest revision |
| `static current()` | the revision of the running request or of the innermost `using()` |
| `static using(R, Closure $callback)` | runs the callback with `R` current, returns what it returns |
| `meta()` | its `Meta` attribute (an empty one when none) |
| `static cases()`, `static from()`, `static tryFrom()` | the native enum methods |

`#[Ark4ne\ApiRevisions\Revisions\Meta(string $description = '', ?string $publishedAt = null, ?string $deprecatedAt =
null, ?string $sunsetAt = null)]` on a case: days `Y-m-d`. It feeds the export, the changelog and the `Deprecation` /
`Sunset` response headers; it never changes behaviour.

## Schema helpers

`Ark4ne\ApiRevisions\Schema\Schema`. Each helper returns a new immutable descriptor; `$source` is always the last
argument: `null` (the key the field is declared under), a key, a dotted path or a closure `($value, $subject)`.

| Helper | Descriptor |
| --- | --- |
| `Schema::string($source = null)` | `Ark4ne\ApiRevisions\Schema\StringSchema` |
| `Schema::int($source = null)` | `Ark4ne\ApiRevisions\Schema\IntSchema` |
| `Schema::float($source = null)` | `Ark4ne\ApiRevisions\Schema\FloatSchema` |
| `Schema::bool($source = null)` | `Ark4ne\ApiRevisions\Schema\BoolSchema` |
| `Schema::date($source = null)` | `Ark4ne\ApiRevisions\Schema\DateSchema` (`DateTimeImmutable`, time kept, serialized with `date_format`) |
| `Schema::dateTime($source = null)` | `Ark4ne\ApiRevisions\Schema\DateTimeSchema` (`DateTimeImmutable`, serialized with `date_time_format`) |
| `Schema::enum(string $enum, $source = null)` | `Ark4ne\ApiRevisions\Schema\EnumSchema`, a backed enum class |
| `Schema::list(Descriptor $items, $source = null)` | `Ark4ne\ApiRevisions\Schema\ListSchema`; each item is the subject of `$items` |
| `Schema::object(array $children, $source = null)` | `Ark4ne\ApiRevisions\Schema\ObjectSchema`; without source the children read the parent's subject |
| `Schema::resource(string $class, $source = null)` | `Ark4ne\ApiRevisions\Schema\ResourceSchema`, another `Revisioned` class at the same revision |
| `Schema::rule(array $rules, $source = null)` | `Ark4ne\ApiRevisions\Schema\RuleSchema`, Laravel rules only, `any` in the export |
| `Schema::since(R, array $fields, $default = <none>)` | the fields, each `->since(R, $default)`: spread it with `...` |
| `Schema::until(R, array $fields)` | the fields, each `->until(R)`: spread it with `...` |
| `Schema::<name>(...)` | a custom type registered in `TypeRegistry` |

`Ark4ne\ApiRevisions\Schema\EagerSchema` wraps a plain value of a resource (`'x' => $this->x`); it is never written
by hand.

## Modifiers

Every descriptor (`Ark4ne\ApiRevisions\Schema\Descriptor`) returns a modified copy:

| Modifier | Effect |
| --- | --- |
| `nullable(bool $nullable = true)` | accepts and renders `null` |
| `required(bool $required = true)` | request: the key must be sent |
| `sometimes(bool $sometimes = true)` | request: validated only when sent |
| `describe(string $description)`, `example(mixed $example)`, `deprecated(bool $deprecated = true)` | export only |
| `rules(mixed ...$rules)` | extra Laravel rules (strings, rule objects, closures) |
| `when(Closure $condition)` | keeps the field when the condition `($value, $subject)` holds, run after the source |
| `whenHas(?string $key = null)` | keeps the field when the subject has the key, before the source |
| `whenLoaded(string $relation)` | keeps the field when the relation is loaded, before the source |
| `since(Revision $revision, mixed $default = <none>)` | exists from `$revision` included; `default:` for older requests |
| `until(Revision $revision)` | exists up to `$revision` included |
| `was(Revision $revision, ?string $name = null, ?Descriptor $schema = null, Closure\|ExplicitConverter\|null $down = null, Closure\|ExplicitConverter\|null $up = null)` | up to `$revision` included, the field had this name and/or type |
| `configure(Configuration $configuration)` | custom types: resolve configured defaults (a date format); returns `$this` by default |
| `configured(Configuration $configuration)` | custom types holding descriptors: a copy with those defaults resolved, for each child |

Type-specific modifiers:

| Descriptor | Modifiers |
| --- | --- |
| `StringSchema` | `min(int $length)`, `max(int $length)`, `format(string $format)` (JSON Schema format: `email`, `uuid`…), `pattern(string $pattern)` |
| `IntSchema`, `FloatSchema` | `min()`, `max()` |
| `ListSchema` | `min(int $size)`, `max(int $size)` |
| `DateSchema`, `DateTimeSchema` | `format(string $format)` (default: `date_format` / `date_time_format`) |
| `ObjectSchema` | `component(string $name)`: exported once under `$defs`, as a `$ref` |

## Conversions

A `was()` with another `schema:` converts the value between the two types. Automatic, as PHP casts: int → float; int,
float, bool → string; bool → int; enum → its backed value; string or int → enum. Automatic but lossy, warned
`conversion-lossy` without a closure: string → int, string → float, float → int, int or string → bool. Anything else
(float ↔ bool, objects, lists, custom types without declared conversions) needs `down:` / `up:`
(`conversion-missing`).

- `down:` (responses) receives the PHP value of the newer shape and the subject, returns the PHP value of the older one.
- `up:` (requests) receives the typed value of the older shape and the snapshot of the step, returns the newer one.
- `Ark4ne\ApiRevisions\Conversion\Cast`: `Cast::round()`, `Cast::floor()`, `Cast::ceil()`, ready-made float → int
  conversions: `->was(R, schema: Schema::float(), up: Cast::round())`.
- `Ark4ne\ApiRevisions\Conversion\ExplicitConverter` is what `down:` / `up:` accept besides a closure (a `Cast`);
  `Ark4ne\ApiRevisions\Conversion\Converter` is the contract of every conversion.

## Responses

A `JsonResource` implements `Ark4ne\ApiRevisions\Schema\Revisioned` and uses
`Ark4ne\ApiRevisions\Laravel\Resources\RevisionedResource`; `toArray()` returns the head descriptors.

- `resolve($request = null)` renders the current revision; `toResponse()`, collections, `with()`, `additional()` and
  wrapping work as usual.
- `static declarationKinds()`: `[DeclarationKind::Response]`.
- Without Laravel, a class implementing `Revisioned` is projected by the core (below).
- A class added or retired implements `Ark4ne\ApiRevisions\Schema\HasLifetime`:
  `static revisionedLifetime(): Lifetime` returns `Lifetime::since(R)`, `Lifetime::until(R)` or `Lifetime::since(A)->withUntil(B)` (inclusive).
  It has no shape outside it (compiling there throws `DefinitionException`): answer 404 when
  `Ark4ne\ApiRevisions\Schema\ClassLifetime::exists($class, $revision)` is false. A `Schema::resource()` field
  nesting it carries the same bounds.

## Requests

A `FormRequest` implements `Revisioned` and uses `Ark4ne\ApiRevisions\Laravel\Requests\RevisionedRequest`.

| Method | Role |
| --- | --- |
| `protected body(): array` | the head descriptors of the body |
| `protected queryParameters(): array` | the head descriptors of the query string |
| `protected upgrade(Revision $to, array $input): array` | the hook, called once per step `X → $to` (`$to` the newer revision of the step), after its renames and conversions, before the defaults of the fields added in `$to`; the fields retired at `X` are dropped after it |
| `rules()` | generated: the rules of the client's revision, keyed by the names it knows (do not override) |
| `validated($key = null, $default = null)`, `safe(?array $keys = null)` | the head input, body and query, serialized back to raw values |
| `protected getValidatorInstance()` | registers the upgrade on the validator (do not override) |
| `static declarationKinds()` | `[DeclarationKind::RequestBody, DeclarationKind::RequestQuery]` |

`Ark4ne\ApiRevisions\Upgrade\UpgradesInput` is the contract of the `upgrade()` hook. An input the upgrade rejects is a
validation error on the client's key.

## Resolving the revision

- `Ark4ne\ApiRevisions\Laravel\Http\ResolveRevision`, middleware alias `revision`: runs the resolver chain, sets the
  current revision, answers **400** on an unknown revision, adds the `response_header`, `Vary`, `Deprecation` and
  `Sunset` headers.
- `Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver`: `resolve(Request $request): ?Revision`; `null` lets the chain go
  on. Its options in `resolvers` are named constructor arguments; the container injects the rest.
- `Ark4ne\ApiRevisions\Laravel\Http\VariesOn`: a resolver reading headers lists them, for the `Vary` header.
- Built in: `Ark4ne\ApiRevisions\Laravel\Http\Resolvers\HeaderResolver` (`name`, default `Api-Revision`),
  `Ark4ne\ApiRevisions\Laravel\Http\Resolvers\PathResolver` (`parameter`, default `revision`),
  `Ark4ne\ApiRevisions\Laravel\Http\Resolvers\QueryResolver` (`name`, default `revision`).
- `Route::revisioned(Closure $routes)`: path mode, prefixes the routes with `{revision}` constrained to the enum values
  and adds the middleware. Needs a `PathResolver` in `resolvers`.

## Outside HTTP

- `ApiRevision::using($revision, $callback)`: render inside the callback.
- `Ark4ne\ApiRevisions\Laravel\Queue\CapturesRevision`: marker of a `ShouldQueue` job; it stores the revision current
  at dispatch and runs `handle()`, its middleware and `failed()` in it.

## Commands

| Command | Role |
| --- | --- |
| `revision:check` | checks every discovered class; exits 1 on an error. `--format=table\|json`, `--list` (the discovered classes only) |
| `revision:lock` | locks every revision but the head that has no lock yet; `--revision=<value>` (re)locks one, the head included |
| `revision:export` | the JSON Schema of every class, kind and revision; `--revision=<value>`, `--class=<class>` |
| `revision:changelog` | Markdown, newest revision first; `--from=<value>` (excluded), `--to=<value>` (included) |
| `revision:prune` | the declarations the oldest revision alone uses, with file and line; always succeeds |
| `revision:cache` | compiles every shape to `cache_path` (run by `php artisan optimize`) |
| `revision:clear` | deletes it (run by `php artisan optimize:clear`) |

## Check codes

Errors fail `revision:check`; warnings do not.

| Code | Severity | Problem |
| --- | --- | --- |
| `order-missing-case`, `order-duplicate-case` | error | `order()` misses or repeats a case |
| `lifetime-inverted` | error | `until` before `since` |
| `since-older-than-group`, `until-newer-than-group` | error | a field bound outside its group's |
| `bound-repeated` | warning | `since()` or `until()` called twice |
| `history-unordered`, `history-before-since`, `history-after-until` | error | a `was()` repeated, on the head, or dead |
| `conversion-missing` | error | a type change neither automatic nor given |
| `conversion-lossy` | warning | a lossy automatic conversion without closure |
| `name-collision` | error | two fields under one name at a revision |
| `required-since-without-default` | error | a `required()->since(R)` without `default:` |
| `schema-override` | warning | a rule's JSON Schema overrides the field's type |
| `native-condition` | warning | `$this->when()`, `mergeWhen()`… in a resource |
| `eager-value` | error | a plain value in a resource |
| `unknown-resource` | error | `Schema::resource()` on a class that cannot be nested |
| `resource-absent` | error | a visible `Schema::resource()` field nesting a class absent at its revision |
| `component-conflict` | error | one component name, two bodies |
| `definition-error` | error | a declaration that throws |
| `lock-mismatch`, `lock-order-changed` | error | a locked revision changed or moved |

## Configuration

`config/revisions.php` (`php artisan vendor:publish --tag=revisions-config`):

| Key | Default | Role |
| --- | --- | --- |
| `enum` | `null` | the revision enum; `null`: the implicit one-case `Ark4ne\ApiRevisions\Revisions\Unrevisioned` (`head`), no history, no revision header |
| `resolvers` | `HeaderResolver` (`Api-Revision`) | the chain, `class => options` |
| `default` | `'latest'` | no resolver answered: `latest`, `oldest`, a backed value, a resolver class |
| `response_header` | `'Api-Revision'` | the header naming the revision of a response |
| `missing_default` | `'error'` | `'null'`: a `required()->since()` without default gets `null` |
| `strict_values` | `null` | value errors throw (`true`) or are cast and reported (`false`); `null` follows `app.debug` |
| `date_format`, `date_time_format` | `'Y-m-d'`, `'Y-m-d\TH:i:sP'` | formats without `format()` |
| `discovery.paths`, `discovery.classes` | `[app_path()]`, `[]` | where the commands find the `Revisioned` classes |
| `cache_path` | `bootstrap/cache/revisions.php` | the compiled shapes |
| `lock_path` | `revisions.lock` | the lock files, one per revision (commit them) |

`Ark4ne\ApiRevisions\Laravel\RevisionsConfig` is the validated configuration (a singleton); add it and
`Ark4ne\ApiRevisions\Compilation\Shapes` to `octane.warm` under Octane.

## Testing

`Ark4ne\ApiRevisions\Testing\InteractsWithRevisions`, on the base test case:

- `withRevision(Revision $revision)`: the next requests carry the revision as the first **built-in** resolver of the
  chain reads it: the header, the path parameter (through `URL::defaults()`, for `route()`) or the query parameter
  (added by `prepareUrlForRequest()`). Custom resolvers are skipped; a chain without a built-in one throws a
  `LogicException`.
- `->assertMatchesRevisionSchema(string $class, ?Revision $revision = null)` on a `TestResponse`: validates the body
  (the `data` of a wrapped body) against the closed schema of the revision named by the `response_header` header, or
  the one given.
- `assertRendersAtEveryRevision(string $class, mixed $subject, ?array $revisions = null)`: renders the subject at every
  revision and validates each rendering.
- `assertRevisionsAreConsistent()`: runs the check, fails on any error.

The schema assertions need `opis/json-schema` (dev).

## Custom types

- `Ark4ne\ApiRevisions\Schema\Descriptor`: extend it; implement `normalize(mixed $value): mixed` (return an
  `Ark4ne\ApiRevisions\Schema\InvalidValue` for a refused value), `serialize(mixed $value): mixed` and
  `protected type(): TypeDefinition` (`Ark4ne\ApiRevisions\Definition\TypeDefinition::custom($name, $jsonSchema)` or
  `TypeDefinition::of(Kind $kind)`, with `Ark4ne\ApiRevisions\Definition\Kind`).
- `Ark4ne\ApiRevisions\Schema\TypeRegistry`: `TypeRegistry::register('money', MoneySchema::class)` at boot, then
  `Schema::money(...)`; `TypeRegistry::has()`, `TypeRegistry::make()`, `TypeRegistry::converters()` (the conversions
  of the registered types) and `TypeRegistry::flush()` (tests).
- `Ark4ne\ApiRevisions\Schema\DeclaresConversions`: a custom type declaring its automatic conversions,
  `static conversions(Converters $converters): Converters`, returning
  `$converters->register('money', Kind::Float, Direction::Down, fn (TypeDefinition $from, TypeDefinition $to) => new
  MoneyToFloat())` (one call per pair and direction).
- `Ark4ne\ApiRevisions\Conversion\Converters`, the immutable registry: `register(Kind|string $from, Kind|string $to,
  Direction $direction, Closure $factory)` returns a copy with the factory of the pair (a side is a `Kind` or the name
  of a custom type; the factory may return `null` to decline); `identity(string $name)` returns a copy declaring that
  the values of the custom type `$name` do not depend on its fragment (a `was(schema:)` of another fragment plans no
  conversion, and the type is exported in place, not as a component), `isIdentity(string $name)` reads it;
  `static builtIn()` (the built-in pairs),
  `find(TypeDefinition $from, TypeDefinition $to, Direction $direction)`, `static key()` and `static keyOf()` (the
  serializable key of a pair) are used by the compiler.
- `Ark4ne\ApiRevisions\Definition\Direction`: `Down` (responses), `Up` (requests).
- `Ark4ne\ApiRevisions\Conversion\Converter`, what a factory returns: `convert(mixed $value, mixed $subject = null)`
  (a normalized value, never `null`, to the target type), `lossless()` (`false` makes an automatic use warn
  `conversion-lossy`), `extraRules()` (validation rules the older request shape adds, such as `numeric`).
- `Ark4ne\ApiRevisions\Schema\DescribesSchema`: a rule object adding its JSON Schema keywords (`toSchema(): array`) to
  the export.
- PHPStan: map each name in `parameters.apiRevisions.types` (`money: App\Schema\MoneySchema`) with the package's
  `extension.neon`.

## Core without Laravel

- `Ark4ne\ApiRevisions\Compilation\Shapes`: `for(string $class, DeclarationKind $kind, Revision $revision)` compiles
  the shape once and memoizes it.
- `Ark4ne\ApiRevisions\Projection\Projector`: `project(Shape $shape, array $descriptors, mixed $subject)` renders it;
  the descriptors come from `$class::describeRevisioned($kind, $subject)`, which `RevisionedResource` and
  `RevisionedRequest` implement; a class outside Laravel implements it and `declarationKinds()` itself.
- `Ark4ne\ApiRevisions\Schema\DeclarationKind`: `Response`, `RequestBody`, `RequestQuery`.

## Exceptions

Every exception implements `Ark4ne\ApiRevisions\Exceptions\RevisionsException`.

| Exception | Thrown on |
| --- | --- |
| `Ark4ne\ApiRevisions\Exceptions\DefinitionException` | a programming error in a declaration (the check reports it with its code) |
| `Ark4ne\ApiRevisions\Exceptions\ConfigurationException` | an invalid `config/revisions.php` |
| `Ark4ne\ApiRevisions\Exceptions\UnknownRevisionException` | a revision the API does not know (rendered 400) |
| `Ark4ne\ApiRevisions\Exceptions\ValueException` | a value the projection cannot render, under `strict_values` |
| `Ark4ne\ApiRevisions\Exceptions\ConversionException` | a value a conversion cannot convert |
| `Ark4ne\ApiRevisions\Exceptions\InvalidInputException` | an input the upgrade rejects (rendered as a validation error) |
| `Ark4ne\ApiRevisions\Exceptions\LockException` | a lock file that cannot be read or written |
| `Ark4ne\ApiRevisions\Exceptions\CacheException` | the compiled shapes file that cannot be read or written |

## Internal contracts

Implemented by the package; an application rarely needs them.

- `Ark4ne\ApiRevisions\Checking\Check`: one rule of the check, `run(CheckContext $context)` adding diagnostics
  (`$context->error()` / `warning()`); a package contributes its own by tagging them
  `Ark4ne\ApiRevisions\Laravel\RevisionsServiceProvider::CHECKS` (built by the container, run after the built-in ones).
- `Ark4ne\ApiRevisions\Checking\ClassFinder`: finds the classes the commands run on.
- `Ark4ne\ApiRevisions\Export\SchemaVisitor`: turns a shape into a format (JSON Schema).
- `Ark4ne\ApiRevisions\Laravel\Validation\RuleMapper`: the Laravel rules a type implies.
- `Ark4ne\ApiRevisions\Projection\SourceReader`: reads a subject (Eloquent without lazy load).
- `Ark4ne\ApiRevisions\Projection\ValueErrorHandler`: throws or reports a value error (`strict_values`).
