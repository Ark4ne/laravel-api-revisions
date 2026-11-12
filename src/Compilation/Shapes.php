<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Compilation;

use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;

/**
 * The repository of compiled shapes: each `(class, declaration kind, revision)` is compiled once by its `Compiler`
 * (model.md D48), then served from memory. One instance per application (a singleton of the Laravel layer), so two
 * configurations never share a shape; `flush()` forgets them (tests, Octane workers reloading code). Custom types are
 * registered at boot, before any `for()`: a cached shape keeps the conversions known when it was compiled.
 */
final class Shapes
{
    /**
     * @var array<string, Shape>
     */
    private array $shapes = [];

    public function __construct(private readonly Compiler $compiler = new Compiler()) {}

    /**
     * The shape of the declaration `$kind` of `$class` at `$revision`, compiled on first use; a compilation that throws
     * stores nothing.
     *
     * @param class-string $class
     */
    public function for(string $class, DeclarationKind $kind, Revision $revision): Shape
    {
        $key = sprintf('%s@%s@%s::%s', $class, $kind->value, $revision::class, $revision->name);

        return $this->shapes[$key] ??= $this->compiler->compile($class, $kind, $revision);
    }

    /**
     * Forgets every compiled shape.
     */
    public function flush(): void
    {
        $this->shapes = [];
    }
}
