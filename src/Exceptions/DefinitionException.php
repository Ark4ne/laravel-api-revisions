<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Exceptions;

use BackedEnum;
use LogicException;
use Throwable;

/**
 * A programming error in a declaration. Named constructors only.
 */
final class DefinitionException extends LogicException implements RevisionsException
{
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function mixedRevisions(BackedEnum $a, BackedEnum $b): self
    {
        return new self(sprintf(
            'Revisions of two enums cannot be mixed: %s::%s and %s::%s.',
            $a::class,
            $a->name,
            $b::class,
            $b->name,
        ));
    }

    /**
     * A revision of another enum where a case of `$expected` is required (`ApiRevision::using(OtherRevision::X)`).
     *
     * @param class-string $expected the revision enum required
     */
    public static function foreignRevision(BackedEnum $revision, string $expected): self
    {
        return new self(sprintf('%s::%s is not a revision of %s.', $revision::class, $revision->name, $expected));
    }

    /**
     * @param class-string $class the revision enum whose `order()` is invalid
     * @param string $reason what is wrong, e.g. "Atlas is missing"
     */
    public static function invalidOrder(string $class, string $reason): self
    {
        return new self(sprintf('%s::order() must list every case exactly once: %s.', $class, $reason));
    }

    /**
     * @param string $argument the `#[Meta]` argument, e.g. `publishedAt`
     */
    public static function invalidMetaDate(string $argument, string $value): self
    {
        return new self(sprintf("%s must be a Y-m-d date, got '%s'.", $argument, $value));
    }

    /**
     * An invalid `#[Meta]` on a revision case (a bad date, a repeated attribute, a wrong argument); `$previous`
     * says why.
     */
    public static function invalidMeta(BackedEnum $case, Throwable $previous): self
    {
        return new self(sprintf('Invalid #[Meta] on %s::%s: %s', $case::class, $case->name, $previous->getMessage()), $previous);
    }

    /**
     * A source that is neither a key nor a closure: an evaluated value, which would run at declaration time.
     *
     * @param string $type the debug type of the value given
     */
    public static function eagerSource(string $type): self
    {
        return new self("A source is a key, a dotted path or a closure, never an evaluated value: got {$type}.");
    }

    /**
     * An empty key, or a dotted path with an empty segment (`customer..name`).
     */
    public static function invalidSourceKey(string $key): self
    {
        return new self("Invalid source key '{$key}': a key or each segment of a dotted path must not be empty.");
    }

    /**
     * A field whose `since` is newer than its `until`: it would exist in no revision.
     */
    public static function invertedLifetime(BackedEnum $since, BackedEnum $until): self
    {
        return new self(sprintf(
            'A field cannot exist from %s::%s until %s::%s: its since is newer than its until.',
            $since::class,
            $since->name,
            $until::class,
            $until->name,
        ));
    }

    /**
     * A member of a `Schema::since()` group declaring an older `since()` than the group.
     */
    public static function sinceOlderThanGroup(string $key, BackedEnum $member, BackedEnum $group): self
    {
        return new self(sprintf(
            "The field '%s' cannot exist since %s::%s in a group since %s::%s: a member's since must be at or after its group's.",
            $key,
            $member::class,
            $member->name,
            $group::class,
            $group->name,
        ));
    }

    /**
     * A member of a `Schema::until()` group declaring a newer `until()` than the group (model.md D24).
     */
    public static function untilNewerThanGroup(string $key, BackedEnum $member, BackedEnum $group): self
    {
        return new self(sprintf(
            "The field '%s' cannot exist until %s::%s in a group until %s::%s: a member's until must be at or before its group's.",
            $key,
            $member::class,
            $member->name,
            $group::class,
            $group->name,
        ));
    }

    /**
     * A member of a group that is not a descriptor.
     */
    public static function invalidGroupMember(string $key, string $type): self
    {
        return new self("Invalid member '{$key}' of a group: a descriptor is expected, got {$type}.");
    }

    /**
     * A member of a group under an int or empty key: it would not spread under its name.
     */
    public static function invalidGroupKey(int|string $key): self
    {
        return new self(sprintf(
            'Invalid member key %s of a group: members are keyed by non-empty strings.',
            is_int($key) ? $key : "'{$key}'",
        ));
    }

    /**
     * `Schema::<name>()` of a type not registered in the `TypeRegistry`.
     */
    public static function unknownType(string $name): self
    {
        return new self("Unknown type '{$name}': register it with TypeRegistry::register('{$name}', …).");
    }

    /**
     * A class compiled that does not implement `Schema\Revisioned`.
     */
    public static function notRevisioned(string $class): self
    {
        return new self("{$class} must implement Ark4ne\\ApiRevisions\\Schema\\Revisioned to be compiled.");
    }

    /**
     * A declaration kind compiled that the class does not declare (`Revisioned::declarationKinds()`).
     */
    public static function undeclaredKind(string $class, string $kind): self
    {
        return new self("{$class} declares no '{$kind}'.");
    }

    /**
     * Descriptors given to the projection that are not those its shape was compiled from: the field `$key` missing, or
     * a composite on one side only.
     */
    public static function missingDescriptor(string $class, string $key): self
    {
        return new self("The descriptors projected for {$class} do not match the field '{$key}' of its shape.");
    }

    /**
     * A response shape (down) given to the upgrader, which upgrades request shapes (up) only (model.md D27).
     */
    public static function notAnUpShape(string $class): self
    {
        return new self("The shape of {$class} is a response shape (down): only a request shape (up) can be upgraded.");
    }

    /**
     * An entry of a declaration that is not a descriptor under a string key.
     */
    public static function invalidDeclaration(string $class, int|string $key, string $type): self
    {
        return new self("The declaration of {$class} must map string keys to descriptors, got {$type} under '{$key}'.");
    }

    /**
     * The subject read while compiling: a declaration reading it eagerly, or a source run (model.md §6.1).
     */
    public static function subjectReadAtCompilation(string $class, string $access): self
    {
        return new self("{$class} read its subject ({$access}) while compiling: declarations must stay lazy (a key, a dotted path or a closure).");
    }

    /**
     * `toArray()` of the resource `$class` failed on the empty subject of the compilation (model.md D61): a plain value
     * reading its subject is evaluated there, every read giving `null`.
     */
    public static function failedDescribing(string $class, Throwable $error): self
    {
        return new self("{$class}::toArray() failed while compiling on an empty subject ({$error->getMessage()}): a plain value is evaluated with every read giving null, make it null-safe (?->) or declare a descriptor.", previous: $error);
    }

    /**
     * A conversion registered for a side no type can have: an empty custom type name, or `Kind::Custom` without a name
     * (`$side` is the side as keyed: `''` or `'custom'`).
     */
    public static function invalidConversionSide(string $side): self
    {
        return new self("Invalid conversion side '{$side}': register a kind, or a custom type by the name its TypeDefinition::custom() declares.");
    }

    /**
     * A custom type registered under a name `Schema` cannot serve: not a method name, or one of its own factories.
     */
    public static function invalidTypeName(string $name): self
    {
        return new self("Invalid type name '{$name}': a custom type needs a PHP method name that Schema does not already declare.");
    }

    /**
     * A custom type whose JSON Schema fragment is not JSON-ready (scalars, arrays and nulls only).
     */
    public static function invalidTypeFragment(string $name): self
    {
        return new self("The JSON Schema fragment of the custom type '{$name}' must hold scalars, arrays and nulls only.");
    }

    /**
     * A custom type whose class does not extend `Schema\Descriptor`.
     */
    public static function invalidCustomType(string $name, string $class): self
    {
        return new self("The custom type '{$name}' must extend Ark4ne\\ApiRevisions\\Schema\\Descriptor, got {$class}.");
    }

    /**
     * A `was()` the history cannot hold: on the head, or on a revision already changed (`history-unordered`).
     */
    public static function invalidHistory(BackedEnum $revision, string $reason): self
    {
        return new self(sprintf('Invalid was(%s::%s): %s.', $revision::class, $revision->name, $reason));
    }

    /**
     * A `was(name: '')`.
     */
    public static function emptyHistoryName(BackedEnum $revision): self
    {
        return new self(sprintf('Invalid was(%s::%s): a name must not be empty.', $revision::class, $revision->name));
    }

    /**
     * A `was()` without name, schema, `down:` nor `up:`: it changes nothing.
     */
    public static function emptyChange(BackedEnum $revision): self
    {
        return new self(sprintf('Invalid was(%s::%s): it changes nothing (give a name, a schema, down: or up:).', $revision::class, $revision->name));
    }

    /**
     * A `was(schema:)` declaring its own source, lifetime, history or default: older values derive from the head value
     * through the down chain (model.md §6.4).
     */
    public static function sourceInHistory(BackedEnum $revision): self
    {
        return new self(sprintf(
            'The schema of was(%s::%s) and its items or children must declare no source, lifetime, history, default nor condition: older values derive from the head value through down:.',
            $revision::class,
            $revision->name,
        ));
    }

    /**
     * A presence key (`whenHas()`) empty, or with an empty segment.
     */
    public static function invalidPresenceKey(string $key): self
    {
        return new self("Invalid presence key '{$key}': a key or each segment of a dotted path must not be empty.");
    }

    /**
     * A rule whose `DescribesSchema::toSchema()` returns something else than JSON-ready keywords.
     */
    public static function invalidJsonSchema(string $rule): self
    {
        return new self("The JSON Schema of the rule {$rule} must be keywords holding scalars, arrays and nulls only.");
    }

    /**
     * `Schema::enum()` of a class that is not a backed enum.
     */
    public static function notBackedEnum(string $class): self
    {
        return new self("Schema::enum() needs a backed enum, got {$class}.");
    }

    /**
     * A child of `Schema::object()` that is not a descriptor.
     *
     * @param string $type the debug type of the child
     */
    public static function invalidChild(string $key, string $type): self
    {
        return new self(sprintf("Invalid child '%s' of Schema::object(): a descriptor is expected, got %s.", $key, $type));
    }

    /**
     * A child of `Schema::object()` under an int or empty key.
     */
    public static function invalidChildKey(int|string $key): self
    {
        return new self(sprintf(
            'Invalid child key %s of Schema::object(): children are keyed by non-empty strings.',
            is_int($key) ? $key : "'{$key}'",
        ));
    }
}
