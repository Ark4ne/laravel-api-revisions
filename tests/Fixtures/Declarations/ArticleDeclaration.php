<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Compilation\Probe;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use ArrayAccess;
use Test\Fixtures\ApiRevision;

/**
 * A consumer's class implementing `Revisioned` itself, without the Laravel traits: one response
 * whose top level holds one object per hook, as a `laravel-json-api` resource declares itself, built on the consumer's
 * own stand-in at compilation (the `Probe` given is ignored). Its `title` closure captures the instance: compilation
 * never runs it on the stand-in, projection runs it on the real subject.
 */
final readonly class ArticleDeclaration implements Revisioned
{
    public function __construct(public mixed $resource) {}

    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        $self = new self($subject instanceof Probe ? new StandIn() : $subject);

        return [
            'attributes' => Schema::object($self->toAttributes()),
            'meta' => Schema::object($self->toMeta()),
        ];
    }

    /**
     * @return array<string, Descriptor<mixed>>
     */
    private function toAttributes(): array
    {
        return [
            'title' => Schema::string(fn(): mixed => is_array($this->resource) || $this->resource instanceof ArrayAccess ? $this->resource['title'] : null)->was(ApiRevision::Winter25, name: 'headline'),
            'views' => Schema::int()->was(ApiRevision::Genesis, schema: Schema::string()),
            'body' => Schema::string()->nullable()->since(ApiRevision::Spring26),
        ];
    }

    /**
     * @return array<string, Descriptor<mixed>>
     */
    private function toMeta(): array
    {
        return [
            'published' => Schema::bool(),
            ...Schema::until(ApiRevision::Winter25, [
                'author' => Schema::string('author.name'),
            ]),
        ];
    }
}
