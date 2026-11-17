<?php

declare(strict_types=1);

namespace Test\Fixtures\Declarations;

use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use DateTimeImmutable;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Order;

/**
 * The order response of the reference scenario (§4.3) without Laravel: a class built on its subject,
 * as a `JsonResource` is, declaring lazy descriptors only.
 */
final readonly class OrderDeclaration implements Revisioned
{
    public function __construct(public mixed $resource) {}

    public static function declarationKinds(): array
    {
        return [DeclarationKind::Response];
    }

    public static function describeRevisioned(DeclarationKind $kind, mixed $subject): array
    {
        return (new self($subject))->toArray();
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
                ->was(ApiRevision::Winter25, schema: Schema::date(), down: static fn(DateTimeImmutable $at): DateTimeImmutable => $at->setTime(0, 0)),
            'delivery' => Schema::object([
                'day' => Schema::date(),
            ]),
            'customer' => Schema::resource(CustomerDeclaration::class),
            ...Schema::since(ApiRevision::Atlas, [
                'tags' => Schema::list(Schema::string()),
                'notes' => Schema::string()->nullable(),
            ]),
            ...Schema::until(ApiRevision::Spring26, [
                'name' => Schema::string(static fn(mixed $value, Order $order): string => $order->fullName()),
            ]),
        ];
    }
}
