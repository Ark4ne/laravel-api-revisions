<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Export;

/**
 * One change a revision makes on one field against the revision before it (model.md D71). Immutable.
 */
final readonly class Change
{
    /**
     * @param string $path the path of the field at the revision (at the revision before it when removed), dotted
     *                     through object children and list items (`lines.*.sku`)
     * @param string|null $previousPath its path at the revision before, for a rename
     * @param array<string, mixed>|null $before the keywords of its type before, for a type change
     * @param array<string, mixed>|null $after the keywords of its type at the revision, for a type change
     */
    public function __construct(
        public ChangeKind $kind,
        public string $path,
        public ?string $previousPath = null,
        public ?array $before = null,
        public ?array $after = null,
    ) {}

    /**
     * The Markdown line of the change, without its bullet.
     */
    public function describe(): string
    {
        return match ($this->kind) {
            ChangeKind::Added => "Added `{$this->path}`.",
            ChangeKind::Removed => "Removed `{$this->path}`.",
            ChangeKind::Renamed => "Renamed `{$this->previousPath}` to `{$this->path}`.",
            ChangeKind::TypeChanged => sprintf('Changed the type of `%s`: `%s` → `%s`.', $this->path, self::json($this->before), self::json($this->after)),
        };
    }

    /**
     * @param array<string, mixed>|null $keywords
     */
    private static function json(?array $keywords): string
    {
        return json_encode((object) ($keywords ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
