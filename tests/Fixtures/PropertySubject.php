<?php

declare(strict_types=1);

namespace Test\Fixtures;

use AllowDynamicProperties;

/**
 * A subject whose properties cover every case `PlainSourceReader` tells apart (model.md D88): a public one, one holding
 * `null`, an uninitialized typed one, an unset one, a static one, non-public ones and dynamic ones; every other key is
 * served by its magic methods, which record their calls.
 */
#[AllowDynamicProperties]
class PropertySubject
{
    public static string $shared = 'static';

    /**
     * @var list<string>
     */
    public array $calls = [];

    public string $title = 'Book';

    public ?string $notes = null;

    public string $pending;

    public string $removed = 'gone';

    protected string $secret = 'protected';

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(private readonly array $attributes = [])
    {
        unset($this->removed); // @phpstan-ignore unset.possiblyHookedProperty (no subclass hooks it)
    }

    public function __get(string $key): mixed
    {
        $this->calls[] = "get:{$key}";

        return $this->attributes[$key] ?? "magic:{$key}";
    }

    public function __isset(string $key): bool
    {
        $this->calls[] = "isset:{$key}";

        return array_key_exists($key, $this->attributes);
    }

    public function secret(): string
    {
        return $this->secret;
    }
}
