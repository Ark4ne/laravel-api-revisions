<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Resources;

use Ark4ne\ApiRevisions\Projection\PlainSourceReader;
use Ark4ne\ApiRevisions\Projection\SourceReader;
use Illuminate\Database\Eloquent\Model;

/**
 * The `SourceReader` of the Laravel layer (Decorator, model.md §6.2, D61): on an Eloquent model, a key is held when it
 * is an attribute present in `getAttributes()` or a loaded relation, `peek()` gives that attribute (cast as usual) or
 * relation, `loaded()` asks `relationLoaded()`: none of them triggers a lazy load, an accessor-only computation or a
 * strict-mode error. Reading a key goes through `__get()` as in the core (attributes, casts and accessors apply). Any
 * other subject is read by the decorated reader.
 */
final readonly class EloquentSourceReader implements SourceReader
{
    public function __construct(private SourceReader $inner = new PlainSourceReader()) {}

    public function read(mixed $subject, array $path): mixed
    {
        return $this->inner->read($subject, $path);
    }

    public function has(mixed $subject, array $path): bool
    {
        foreach ($path as $key) {
            $held = $subject instanceof Model
                ? array_key_exists($key, $subject->getAttributes()) || $subject->relationLoaded($key)
                : $this->inner->has($subject, [$key]);
            if (!$held) {
                return false;
            }
            $subject = $this->inner->read($subject, [$key]);
        }

        return true;
    }

    public function loaded(mixed $subject, array $path): bool
    {
        foreach ($path as $key) {
            $held = $subject instanceof Model ? $subject->relationLoaded($key) : $this->inner->loaded($subject, [$key]);
            if (!$held) {
                return false;
            }
            $subject = $subject instanceof Model ? $subject->getRelation($key) : $this->inner->read($subject, [$key]);
        }

        return true;
    }

    public function peek(mixed $subject, string $key): mixed
    {
        if (!$subject instanceof Model) {
            return $this->inner->peek($subject, $key);
        }
        if (array_key_exists($key, $subject->getAttributes())) {
            return $subject->getAttributeValue($key);
        }

        return $subject->relationLoaded($key) ? $subject->getRelation($key) : null;
    }
}
