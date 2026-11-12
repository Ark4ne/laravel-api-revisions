<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Customer;
use Test\Fixtures\Enums\OrderStatus;

/**
 * The order response of the reference scenario (model.md §4.2, §4.3) without Laravel: a class built on its subject,
 * as a `JsonResource` is, declaring lazy descriptors only.
 */
final readonly class OrderDeclaration implements Revisioned
{
    public function __construct(public mixed $resource) {}

    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $probe): array
    {
        return (new self($probe))->toArray();
    }

    /**
     * @return array<string, Descriptor<mixed>>
     */
    public function toArray(): array
    {
        return [
            'title' => Schema::string(),
            'status' => Schema::enum(OrderStatus::class)
                ->was(ApiRevision::Winter25, name: 'state'),
            'total' => Schema::int()
                ->was(
                    ApiRevision::Winter25,
                    schema: Schema::string(),
                    down: static fn(int $cents): string => number_format($cents / 100, 2, '.', ''),
                )
                ->was(ApiRevision::Genesis, schema: Schema::float()),
            'placed_at' => Schema::dateTime()
                ->was(ApiRevision::Winter25, schema: Schema::date()),
            'delivery' => Schema::object([
                'day' => Schema::date(),
            ]),
            'customer' => Schema::resource(CustomerDeclaration::class),
            ...Schema::since(ApiRevision::Atlas, [
                'tags' => Schema::list(Schema::string()),
                'notes' => Schema::string()->nullable(),
            ]),
            ...Schema::until(ApiRevision::Spring26, [
                'name' => Schema::string(static fn(mixed $value, Customer $customer): string => "{$customer->firstName} {$customer->lastName}"),
            ]),
        ];
    }
}
