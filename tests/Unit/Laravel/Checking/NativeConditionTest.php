<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Checking;

use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Laravel\Checking\NativeCondition;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Resources\CustomerResource;
use Test\Fixtures\Resources\InvertedResource;
use Test\Fixtures\Resources\NativeResource;
use Test\TestCase;

final class NativeConditionTest extends TestCase
{
    public function test_a_native_condition_in_a_resource_is_reported(): void
    {
        $message = 'is a Laravel native condition, honoured at render but outside the shape, the export and the lock: declare ->when() / ->whenLoaded() on a descriptor.';

        self::assertSame(
            [
                ['native-condition', 'warning', 'secret', "'secret' (response) {$message}"],
                ['native-condition', 'warning', null, "A mergeWhen() (response) {$message}"],
                ['native-condition', 'warning', 'buyer', "'buyer' (response) {$message}"],
                ['native-condition', 'warning', 'buyer_resource', "'buyer_resource' (response) {$message}"],
            ],
            self::diagnose(NativeResource::class),
        );
    }

    public function test_a_resource_without_native_condition_and_a_plain_declaration_report_nothing(): void
    {
        self::assertSame([], self::diagnose(CustomerResource::class));
        self::assertSame([], self::diagnose(OrderDeclaration::class));
    }

    public function test_a_declaration_error_of_a_resource_keeps_its_code(): void
    {
        $finder = new readonly class implements ClassFinder {
            public function find(): array
            {
                return [InvertedResource::class];
            }
        };

        $diagnostics = (new Checker(ApiRevision::class, $finder, [new NativeCondition()]))->run()->all();

        self::assertCount(1, $diagnostics);
        self::assertSame('lifetime-inverted', $diagnostics[0]->code);
    }

    /**
     * @param class-string<Revisioned> $class
     *
     * @return list<array{string, string, ?string, string}>
     */
    private static function diagnose(string $class): array
    {
        $finder = new readonly class ($class) implements ClassFinder {
            /**
             * @param class-string<Revisioned> $class
             */
            public function __construct(private string $class) {}

            public function find(): array
            {
                return [$this->class];
            }
        };
        $found = [];
        foreach ((new Checker(ApiRevision::class, $finder, [new NativeCondition()]))->run() as $diagnostic) {
            self::assertSame($class, $diagnostic->class);
            self::assertNull($diagnostic->revision);
            $found[] = [$diagnostic->code, $diagnostic->severity->value, $diagnostic->field, $diagnostic->message];
        }

        return $found;
    }
}
