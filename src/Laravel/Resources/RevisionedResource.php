<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Resources;

use Ark4ne\ApiRevisions\Compilation\Probe;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\EagerSchema;
use Ark4ne\ApiRevisions\Support\Memo;
use Illuminate\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Http\Resources\PotentiallyMissing;
use JsonSerializable;
use Throwable;

/**
 * Makes a `JsonResource` revisioned (model.md §6.6, D61): its `toArray()` returns descriptors, `resolve()` projects
 * them on `$this->resource` at the current revision and leaves the rest of `JsonResource` (`with()`, `additional()`,
 * wrapping, collections, pagination) unchanged. Implements the `Revisioned` bridge: the class declares a response.
 *
 * In `toArray()`, a plain value is rendered as it is in every revision (a nullable `any` in the shape, `eager-value`);
 * a native `when()`, `whenLoaded()` or `mergeWhen()` is honoured at run time and kept out of the shape
 * (`native-condition`): declare `->when()` / `->whenLoaded()` on the descriptor instead. The `any` values that are
 * `Arrayable` are rendered as their array.
 *
 * @phpstan-require-extends \Illuminate\Http\Resources\Json\JsonResource
 * @phpstan-require-implements \Ark4ne\ApiRevisions\Schema\Revisioned
 */
trait RevisionedResource
{
    /**
     * @return non-empty-list<DeclarationKind>
     */
    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    /**
     * The descriptors of `toArray()`, the resource built on `$subject`: on a permissive subject at compilation
     * (`DescribeSubject`: plain values and native conditions evaluate on `null`s, the native conditions missing there
     * stay out of the shape), on the subject of a nested resource at projection (a native condition missing there, or a
     * key of the compiled declaration left out, is a field never rendered). A plain value becomes an `EagerSchema`, a
     * `MergeValue` is left out.
     *
     * @return array<string, \Ark4ne\ApiRevisions\Schema\Descriptor<mixed>>
     *
     * @internal called by the compiler and the projector
     */
    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        $describing = $subject instanceof Probe;
        $resource = new static($describing ? new DescribeSubject() : $subject);
        $descriptors = [];
        foreach ($resource->revisionedDeclaration($describing) as $key => $value) {
            if (!$value instanceof MergeValue && !($describing && self::revisionedMissing($value))) {
                $descriptors[$key] = $resource->revisionedDescriptor($value);
            }
        }
        if (!$describing) {
            // a key of the compiled declaration that `toArray()` left out on this subject (a native condition decided by
            // a PHP `if`): never rendered, as at the top level
            $declared = Memo::remember('revisioned-resource:' . static::class, static fn(): array => array_keys(static::describeRevisioned($kind, new Probe(static::class))));
            foreach ($declared as $key) {
                $descriptors[$key] ??= $resource->revisionedDescriptor(null, true);
            }
        }

        // a key that is not a string is refused by the compiler
        /** @var array<string, Descriptor<mixed>> $descriptors */
        return $descriptors;
    }

    /**
     * The resource at the current revision: the descriptors of `toArray()` projected on `$this->resource`, the plain
     * values and native conditions as `JsonResource` renders them, in the order of `toArray()`.
     *
     * @param Request|null $request
     *
     * @return array<array-key, mixed>
     */
    public function resolve($request = null)
    {
        $data = $this->revisionedData($request ?? $this->resolveRequestFromContainer());
        $container = Container::getInstance();
        $revision = $container->make(RevisionScope::class)->current($container->make(RevisionsConfig::class)->enum);
        $shape = $container->make(Shapes::class)->for(static::class, DeclarationKind::Response, $revision);

        $descriptors = [];
        foreach (array_keys($shape->fields) as $key) {
            $descriptors[$key] = $this->revisionedDescriptor(array_key_exists($key, $data) ? $data[$key] : null, !array_key_exists($key, $data));
        }
        $projected = $container->make(Projector::class)->project($shape, $descriptors, $this->resource);

        $rendered = [];
        foreach ($this->filter($data) as $key => $value) {
            $field = is_string($key) ? $shape->field($key) : null;
            if ($field !== null) {
                if (array_key_exists($field->name, $projected)) {
                    $rendered[$field->name] = $projected[$field->name];
                }
            } elseif (!$value instanceof Descriptor) {
                // a plain value, or a native condition met: as a `JsonResource` renders it
                $rendered[$key] = $value;
            }
        }

        return array_map(self::revisionedArrayable(...), $rendered);
    }

    /**
     * What `toArray()` returns: at compilation with an empty request (the shape never depends on the request served),
     * a failure of a plain value evaluated on the `DescribeSubject` thrown as `DefinitionException::failedDescribing()`;
     * else with the request of the container.
     *
     * @return array<array-key, mixed>
     */
    private function revisionedDeclaration(bool $describing): array
    {
        if (!$describing) {
            return $this->revisionedData($this->resolveRequestFromContainer());
        }
        try {
            return $this->revisionedData(new Request());
        } catch (Throwable $error) {
            throw DefinitionException::failedDescribing(static::class, $error);
        }
    }

    /**
     * What `toArray()` returns, as an array (`JsonResource::toArray()` may return an `Arrayable` or a
     * `JsonSerializable`).
     *
     * @return array<array-key, mixed>
     */
    private function revisionedData(Request $request): array
    {
        return self::revisionedArray($this->toArray($request));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function revisionedArray(mixed $data): array
    {
        if ($data instanceof Arrayable) {
            return $data->toArray();
        }

        return (array) ($data instanceof JsonSerializable ? $data->jsonSerialize() : $data);
    }

    /**
     * The descriptor of the value `$value` of `toArray()`: itself, a field never rendered for a native condition
     * missing (or a key `toArray()` did not return, `$absent`), else an `EagerSchema` of the value filtered as
     * `JsonResource` filters it.
     *
     * @return Descriptor<mixed>
     */
    private function revisionedDescriptor(mixed $value, bool $absent = false): Descriptor
    {
        return match (true) {
            $value instanceof Descriptor => $value,
            $absent || self::revisionedMissing($value) => EagerSchema::of(null)->when(static fn(): bool => false),
            default => EagerSchema::of(is_array($value) ? $this->filter($value) : $value),
        };
    }

    /**
     * Whether `$value` is a native condition missing: a `MissingValue`, or a resource built on one.
     */
    private static function revisionedMissing(mixed $value): bool
    {
        if ($value instanceof JsonResource) {
            $value = $value->resource;
        }

        return $value instanceof PotentiallyMissing && $value->isMissing();
    }

    /**
     * `$value` with every `Arrayable` (an `any` value, a plain value) as its array (model.md §6.6).
     */
    private static function revisionedArrayable(mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        return is_array($value) ? array_map(self::revisionedArrayable(...), $value) : $value;
    }
}
