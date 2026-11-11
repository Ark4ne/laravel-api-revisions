<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Schema;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Closure;

/**
 * Where a field reads its value on the subject: the key it is declared under, a key (dotted paths allowed) or a
 * closure called with `($value, $subject)`. Never an evaluated value: a schema compiles without a subject.
 */
final readonly class Source
{
    /**
     * @param list<string> $path empty for the declared key, the parent subject and a closure
     * @param Closure|null $closure called with `($value, $subject)` (`Support\Invoke`)
     * @param bool $parent the subject of the parent itself (an object without source)
     */
    private function __construct(
        public array $path,
        public ?Closure $closure,
        public bool $parent = false,
    ) {}

    /**
     * A source as declared: `null` (the declared key), a key or dotted path, a closure, or a `Source` already built.
     * Anything else is an evaluated value and throws `DefinitionException::eagerSource()`.
     */
    public static function from(mixed $source): self
    {
        return match (true) {
            $source instanceof self => $source,
            $source === null => self::declaredKey(),
            is_string($source) => self::key($source),
            $source instanceof Closure => self::closure($source),
            default => throw DefinitionException::eagerSource(get_debug_type($source)),
        };
    }

    /**
     * A key of the subject, or a dotted path (`customer.name`) read segment by segment.
     */
    public static function key(string $key): self
    {
        $path = explode('.', $key);
        if (in_array('', $path, true)) {
            throw DefinitionException::invalidSourceKey($key);
        }

        return new self($path, null);
    }

    /**
     * @param Closure $closure called with `($value, $subject)`, any prefix of them declared (`Support\Invoke`)
     */
    public static function closure(Closure $closure): self
    {
        return new self([], $closure);
    }

    /**
     * The key the field is declared under (its head key).
     */
    public static function declaredKey(): self
    {
        return new self([], null);
    }

    /**
     * The subject of the parent: an object without source groups fields read on the parent's subject (model.md §6.1).
     */
    public static function parent(): self
    {
        return new self([], null, true);
    }

    public function isParent(): bool
    {
        return $this->parent;
    }

    public function isDeclaredKey(): bool
    {
        return $this->path === [] && $this->closure === null && !$this->parent;
    }

    public function isClosure(): bool
    {
        return $this->closure !== null;
    }
}
