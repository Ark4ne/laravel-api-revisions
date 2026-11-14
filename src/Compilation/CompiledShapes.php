<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Compilation;

use Ark4ne\ApiRevisions\Exceptions\CacheException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;

/**
 * The compiled shapes file written by `revision:cache` (model.md §10, D56): a PHP file returning, by
 * `class@kind@revision`, the serialized `Shape` (closure-free, D45). An array of strings, so OPcache keeps it in shared
 * memory and a shape is unserialized only when first asked. Read by `Shapes` before compiling. Immutable.
 */
final readonly class CompiledShapes
{
    /**
     * The entry stamping the format of the file: bumped whenever `Shape` or a class it holds changes its properties,
     * so a file written by another release is refused instead of unserializing a stale shape.
     */
    public const string FORMAT_KEY = '@format';

    public const int FORMAT = 2;

    /**
     * @param array<string, string> $shapes the serialized shapes, by key
     */
    private function __construct(
        private array $shapes,
        private string $path = '',
    ) {}

    /**
     * No compiled shape.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The shapes of the file at `$path`; none when there is no file. `CacheException::invalidFile()` when the file
     * holds something else, or was written in another format (another release of the library).
     */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            return self::empty();
        }
        $shapes = require $path;
        if (!is_array($shapes) || ($shapes[self::FORMAT_KEY] ?? null) !== (string) self::FORMAT) {
            throw CacheException::invalidFile($path);
        }
        unset($shapes[self::FORMAT_KEY]);
        $serialized = [];
        foreach ($shapes as $key => $shape) {
            if (!is_string($key) || !is_string($shape)) {
                throw CacheException::invalidFile($path);
            }
            $serialized[$key] = $shape;
        }

        return new self($serialized, $path);
    }

    /**
     * Writes `$shapes` to the file at `$path`, replacing it at once (a reader never sees half a file).
     * `CacheException::unwritable()` when it cannot be written, `uncompiledShape()` for a shape without its class.
     *
     * @param iterable<Shape> $shapes
     */
    public static function write(string $path, iterable $shapes): void
    {
        $serialized = [self::FORMAT_KEY => (string) self::FORMAT];
        foreach ($shapes as $shape) {
            if ($shape->class === null || $shape->kind === null) {
                throw CacheException::uncompiledShape();
            }
            $serialized[self::key($shape->class, $shape->kind, $shape->revision)] = serialize($shape);
        }
        $content = "<?php\n\n// Compiled by `php artisan revision:cache`: do not edit.\n\nreturn " . var_export($serialized, true) . ";\n";
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        // a warning would become an exception in Laravel: the failure is reported as the CacheException instead
        if (@file_put_contents($temporary, $content) === false) {
            throw CacheException::unwritable($path);
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw CacheException::unwritable($path);
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }

    /**
     * The key of a shape, in memory (`Shapes`) and in the file.
     *
     * @param class-string $class
     */
    public static function key(string $class, DeclarationKind $kind, Revision $revision): string
    {
        return sprintf('%s@%s@%s::%s', $class, $kind->value, $revision::class, $revision->name);
    }

    /**
     * The compiled shape of the declaration `$kind` of `$class` at `$revision`, `null` when not in the file;
     * `CacheException::invalidFile()` when its entry is not a serialized shape.
     *
     * @param class-string $class
     */
    public function get(string $class, DeclarationKind $kind, Revision $revision): ?Shape
    {
        $serialized = $this->shapes[self::key($class, $kind, $revision)] ?? null;
        if ($serialized === null) {
            return null;
        }
        // a corrupted entry emits a warning, which Laravel would turn into an exception: refused as invalid instead
        $shape = @unserialize($serialized);

        return $shape instanceof Shape ? $shape : throw CacheException::invalidFile($this->path);
    }

    /**
     * The number of compiled shapes.
     */
    public function count(): int
    {
        return count($this->shapes);
    }
}
