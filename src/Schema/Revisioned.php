<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

/**
 * A class declaring head descriptors with their history (a resource, a request): what the compiler turns into a shape
 * per declaration kind and revision. Implemented through the traits of the Laravel layer
 * (`RevisionedResource`, `RevisionedRequest`), or directly by a consumer or a class outside Laravel: such
 * a class is compiled, cached, checked, locked and exported like the others.
 */
interface Revisioned
{
    /**
     * The kinds of declaration the class makes, each compiled on its own.
     *
     * @return non-empty-list<DeclarationKind>
     */
    public static function declarationKinds(): array;

    /**
     * The head descriptors of the declaration `$kind`, by key (groups spread), the class built on `$subject`: the compiler gives a `Compilation\Probe`, a subject that throws on any access, so a declaration reading its
     * subject eagerly fails at compilation; the projector gives the value of a nested resource (`Schema::resource()`),
     * so the closures of the declaration reach it. A class implementing it itself may build its declaration on its own
     * stand-in instead of the probe; it must still run no source, closure or condition while declaring.
     *
     * @param mixed $subject the probe at compilation, the subject of the nested resource at projection
     *
     * @return array<string, Descriptor<mixed>>
     */
    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array;
}
