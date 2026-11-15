<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Projection;

use Ark4ne\ApiRevisions\Compilation\FieldDefinition;
use Ark4ne\ApiRevisions\Conversion\ConversionChain;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Closure;

/**
 * One field (or item) of a `ProjectionPlan` (model.md D86): what the pipeline of `Projector` decides from the shape and
 * the descriptor, resolved once per render — its presences, its source, its conditions, its bound chain, its
 * configured serializer and its nested plan.
 *
 * @internal built by `Projector::plan()`, run by `ProjectionPlan`
 */
final readonly class PlannedField
{
    /**
     * @param string|null $key its head key; `null` for an item of a list, whose subject is the element itself
     * @param Descriptor<mixed> $descriptor the head descriptor, which normalizes
     * @param Descriptor<mixed> $serializer the descriptor in force at the revision, configured
     * @param list<array{bool, non-empty-list<string>}> $presences each presence condition: whether a relation, its path
     * @param Closure|null $closure the source closure
     * @param non-empty-list<string>|null $path the path read on the subject; `null` without closure: the subject itself
     * @param list<Closure> $conditions the `when()` conditions
     * @param ConversionChain|null $chain the down chain bound to its converters; `null` when empty
     * @param list<PlannedField>|null $children the children of an object
     * @param class-string<Revisioned>|null $resource the class of a nested resource
     */
    public function __construct(
        public FieldDefinition $definition,
        public ?string $key,
        public Descriptor $descriptor,
        public Descriptor $serializer,
        public array $presences,
        public ?Closure $closure,
        public ?array $path,
        public array $conditions,
        public ?ConversionChain $chain,
        public ?array $children = null,
        public ?PlannedField $items = null,
        public ?string $resource = null,
    ) {}
}
