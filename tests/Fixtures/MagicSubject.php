<?php

declare(strict_types=1);

namespace Test\Fixtures;

use RuntimeException;

/**
 * A subject read through magic methods, as an Eloquent model is: records every `__get()` and `__isset()`, and refuses
 * a missing attribute in `__get()` like a strict mode.
 */
final class MagicSubject
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    public string $plain = 'public';

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(private readonly array $attributes = []) {}

    public function __get(string $key): mixed
    {
        $this->calls[] = "get:{$key}";
        if (!array_key_exists($key, $this->attributes)) {
            throw new RuntimeException("Missing attribute {$key}.");
        }

        return $this->attributes[$key];
    }

    public function __isset(string $key): bool
    {
        $this->calls[] = "isset:{$key}";

        return isset($this->attributes[$key]);
    }
}
