<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Compilation;

use Ark4ne\ApiRevisions\Conversion\Converters;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;

/**
 * The repository of compiled shapes: each `(class, declaration kind, revision)` is compiled once by its `Compiler`
 * (model.md D48), then served from memory. One instance per application (a singleton of the Laravel layer), so two
 * configurations never share a shape; `flush()` forgets them (tests, Octane workers reloading code). Custom types are
 * registered at boot, before any `for()`: a cached shape keeps the conversions known when it was compiled. The shapes
 * of the compiled file (`revision:cache`, `CompiledShapes`) are read before compiling.
 */
final class Shapes
{
    /**
     * @var array<string, Shape>
     */
    private array $shapes = [];

    private readonly CompiledShapes $compiled;

    public function __construct(private readonly Compiler $compiler = new Compiler(), ?CompiledShapes $compiled = null)
    {
        $this->compiled = $compiled ?? CompiledShapes::empty();
    }

    /**
     * The shape of the declaration `$kind` of `$class` at `$revision`, read from the compiled file or compiled on first
     * use; a compilation that throws stores nothing.
     *
     * @param class-string $class
     */
    public function for(string $class, DeclarationKind $kind, Revision $revision): Shape
    {
        $key = CompiledShapes::key($class, $kind, $revision);

        return $this->shapes[$key] ??= $this->compiled->get($class, $kind, $revision) ?? $this->compiler->compile($class, $kind, $revision);
    }

    /**
     * The registry the shapes are planned against (`Compiler::converters()`).
     */
    public function converters(): Converters
    {
        return $this->compiler->converters();
    }

    /**
     * Forgets every shape compiled or read; the compiled file stays (`revision:clear` removes it).
     */
    public function flush(): void
    {
        $this->shapes = [];
    }
}
