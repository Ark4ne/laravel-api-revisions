<?php

declare(strict_types=1);

namespace Test\Unit\Projection;

use Ark4ne\ApiRevisions\Compilation\Shape;
use Ark4ne\ApiRevisions\Compilation\ShapeSelector;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\TestCase;

final class ProjectionPlanTest extends TestCase
{
    public function test_a_plan_runs_on_each_subject_with_its_own_values(): void
    {
        $seen = [];
        $head = [
            'title' => Schema::string()->was(ApiRevision::Winter25, name: 'name'),
            'label' => Schema::string(static function (mixed $value, array $subject) use (&$seen): string {
                $title = $subject['title'] ?? null;
                self::assertIsString($title);
                $seen[] = $title;

                return strtoupper($title);
            }),
            'notes' => Schema::string()->nullable()->when(static fn(array $subject): bool => ($subject['notes'] ?? null) !== null),
        ];
        $plan = (new Projector())->plan(self::shape($head, ApiRevision::Genesis), $head);

        self::assertSame(['name' => 'Lamp', 'label' => 'LAMP', 'notes' => 'fragile'], $plan->run(['title' => 'Lamp', 'notes' => 'fragile']));
        self::assertSame(['name' => 'Desk', 'label' => 'DESK'], $plan->run(['title' => 'Desk', 'notes' => null]));
        self::assertSame(['Lamp', 'Desk'], $seen);
    }

    public function test_a_plan_keeps_the_head_keys_given_in_the_head_order(): void
    {
        $head = [
            'title' => Schema::string()->was(ApiRevision::Winter25, name: 'name'),
            'total' => Schema::int(),
            'notes' => Schema::string(),
        ];
        $projector = new Projector();
        $shape = self::shape($head, ApiRevision::Genesis);
        $subject = ['title' => 'Lamp', 'total' => 1250, 'notes' => 'fragile'];

        self::assertSame(['name' => 'Lamp', 'notes' => 'fragile'], $projector->plan($shape, $head, ['notes', 'title', 'unknown'])->run($subject));
        self::assertSame([], $projector->plan($shape, $head, [])->run($subject));
        self::assertSame($projector->project($shape, $head, $subject), $projector->plan($shape, $head)->run($subject));
    }

    public function test_a_key_kept_must_be_a_head_key(): void
    {
        $head = ['title' => Schema::string()->was(ApiRevision::Winter25, name: 'name')];

        self::assertSame([], (new Projector())->plan(self::shape($head, ApiRevision::Genesis), $head, ['name'])->run(['title' => 'Lamp']));
    }

    public function test_the_descriptors_are_checked_against_the_shape_when_planning(): void
    {
        $head = ['title' => Schema::string(), 'total' => Schema::int()];

        $this->expectException(DefinitionException::class);

        (new Projector())->plan(self::shape($head, ApiRevision::Genesis), ['title' => Schema::string()]);
    }

    public function test_a_key_left_out_needs_no_descriptor(): void
    {
        $head = ['title' => Schema::string(), 'total' => Schema::int()];

        self::assertSame(['title' => 'Lamp'], (new Projector())->plan(self::shape($head, ApiRevision::Genesis), ['title' => Schema::string()], ['title'])->run(['title' => 'Lamp']));
    }

    public function test_a_nested_resource_is_declared_per_value(): void
    {
        $head = ['items' => Schema::list(Schema::resource(CountingDeclaration::class))];
        $plan = (new Projector())->plan(self::shape($head, ApiRevision::Genesis), $head);
        // the shape of the nested resource compiled by a first run
        $plan->run(['items' => []]);
        $plan->run(['items' => [['title' => 'Warm']]]);
        CountingDeclaration::$described = 0;

        $first = $plan->run(['items' => [['title' => 'Lamp'], ['title' => 'Desk']]]);
        $second = $plan->run(['items' => [['title' => 'Vase']]]);

        self::assertSame(['items' => [['name' => 'Lamp'], ['name' => 'Desk']]], $first);
        self::assertSame(['items' => [['name' => 'Vase']]], $second);
        // one declaration per value (its subject), the shape compiled once
        self::assertSame(3, CountingDeclaration::$described);
    }

    public function test_a_member_left_out_is_never_evaluated(): void
    {
        $calls = [];
        $head = [
            'title' => Schema::string(),
            'address' => Schema::object([
                'city' => Schema::string(),
                'zip' => Schema::string(static function () use (&$calls): string {
                    $calls[] = 'source';

                    return '75001';
                })->when(static function () use (&$calls): bool {
                    $calls[] = 'when';

                    return true;
                }),
                'floor' => Schema::int(),
            ], 'address')->when(static function () use (&$calls): bool {
                $calls[] = 'address';

                return true;
            }),
        ];
        $plan = (new Projector())->plan(self::shape($head, ApiRevision::Atlas), $head, ['address.city']);

        // `floor` holds a refused value: left out, it raises no error
        self::assertSame(['address' => ['city' => 'Paris']], $plan->run(['title' => 'Lamp', 'address' => ['city' => 'Paris', 'floor' => 'high']]));
        self::assertSame(['address'], $calls);
    }

    public function test_a_member_left_out_runs_neither_its_presences_nor_its_conversions(): void
    {
        $calls = [];
        $head = ['address' => Schema::object([
            'city' => Schema::string(),
            'zip' => Schema::string(static function () use (&$calls): string {
                $calls[] = 'source';

                return '75001';
            })->whenHas('postcode')->was(ApiRevision::Winter25, down: static function (mixed $zip) use (&$calls): mixed {
                $calls[] = 'down';

                return $zip;
            }),
        ], 'address')];
        $plan = (new Projector())->plan(self::shape($head, ApiRevision::Genesis), $head, ['address.city']);
        $subject = new class {
            /**
             * @var array<string, string>
             */
            public array $address = ['city' => 'Paris', 'postcode' => '75001'];
        };

        self::assertSame(['address' => ['city' => 'Paris']], $plan->run($subject));
        self::assertSame([], $calls);
    }

    public function test_an_object_in_the_items_of_a_list_keeps_its_members_through_a_star(): void
    {
        $head = ['tags' => Schema::list(Schema::object([
            'name' => Schema::string(),
            'meta' => Schema::object(['x' => Schema::int(), 'y' => Schema::int()], 'meta'),
        ]))];
        $plan = (new Projector())->plan(self::shape($head, ApiRevision::Atlas), $head, ['tags.*.meta.x']);

        self::assertSame(['tags' => [['meta' => ['x' => 1]]]], $plan->run(['tags' => [['name' => 'gift', 'meta' => ['x' => 1, 'y' => 'refused']]]]));
    }

    public function test_a_head_key_holding_a_dot_is_kept_whole(): void
    {
        $head = ['meta.version' => Schema::int(), 'meta' => Schema::object(['version' => Schema::string()], 'meta')];
        $subject = ['meta.version' => 2, 'meta' => ['version' => 'v1']];
        $projector = new Projector();
        $shape = self::shape($head, ApiRevision::Atlas);

        self::assertSame(['meta.version' => 2], $projector->plan($shape, $head, ['meta.version'])->run($subject));
    }

    public function test_paths_keep_the_union_of_their_branches_at_every_level_in_the_head_order(): void
    {
        $head = [
            'title' => Schema::string(),
            'address' => Schema::object([
                'city' => Schema::string(),
                'geo' => Schema::object(['lat' => Schema::float(), 'lng' => Schema::float()], 'geo'),
                'zip' => Schema::string(),
            ], 'address'),
        ];
        $subject = ['title' => 'Lamp', 'address' => ['city' => 'Paris', 'geo' => ['lat' => 48.8, 'lng' => 2.3], 'zip' => '75001']];
        $projector = new Projector();
        $shape = self::shape($head, ApiRevision::Atlas);

        self::assertSame(
            ['title' => 'Lamp', 'address' => ['city' => 'Paris', 'geo' => ['lat' => 48.8]]],
            $projector->plan($shape, $head, ['address.geo.lat', 'title', 'address.city'])->run($subject),
        );
    }

    public function test_the_shorter_path_wins(): void
    {
        $head = ['address' => Schema::object(['city' => Schema::string(), 'zip' => Schema::string()], 'address')];
        $subject = ['address' => ['city' => 'Paris', 'zip' => '75001']];
        $projector = new Projector();
        $shape = self::shape($head, ApiRevision::Atlas);

        self::assertSame($subject, $projector->plan($shape, $head, ['address.city', 'address'])->run($subject));
        self::assertSame($subject, $projector->plan($shape, $head, ['address', 'address.city'])->run($subject));
    }

    public function test_a_member_is_kept_by_its_head_key_and_rendered_under_its_name_at_the_revision(): void
    {
        $head = ['address' => Schema::object([
            'city' => Schema::string()->was(ApiRevision::Winter25, name: 'town'),
            'zip' => Schema::string(),
        ], 'address')];
        $plan = (new Projector())->plan(self::shape($head, ApiRevision::Genesis), $head, ['address.city', 'address.town']);

        self::assertSame(['address' => ['town' => 'Paris']], $plan->run(['address' => ['city' => 'Paris', 'zip' => '75001']]));
    }

    public function test_an_unknown_path_is_ignored(): void
    {
        $head = ['title' => Schema::string(), 'address' => Schema::object(['city' => Schema::string()], 'address')];
        $subject = ['title' => 'Lamp', 'address' => ['city' => 'Paris']];
        $projector = new Projector();
        $shape = self::shape($head, ApiRevision::Atlas);

        self::assertSame([], $projector->plan($shape, $head, ['address.unknown', 'unknown.city', 'address.'])->run($subject));
        self::assertSame(['title' => 'Lamp'], $projector->plan($shape, $head, ['address.unknown', 'title'])->run($subject));
    }

    public function test_a_star_goes_through_the_items_of_a_list(): void
    {
        $calls = 0;
        $head = ['tags' => Schema::list(Schema::object([
            'name' => Schema::string(),
            'color' => Schema::string(static function () use (&$calls): string {
                ++$calls;

                return 'red';
            }),
        ]))];
        $subject = ['tags' => [['name' => 'gift'], ['name' => 'fragile']]];
        $projector = new Projector();
        $shape = self::shape($head, ApiRevision::Atlas);

        self::assertSame(['tags' => [['name' => 'gift'], ['name' => 'fragile']]], $projector->plan($shape, $head, ['tags.*.name'])->run($subject));
        self::assertSame(0, $calls);
        self::assertSame([], $projector->plan($shape, $head, ['tags.*.unknown'])->run($subject));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function pathsKeepingTheFieldWhole(): iterable
    {
        yield 'a list through a key' => [['tags.name']];
        yield 'a list through a star' => [['tags.*']];
        yield 'a scalar' => [['title.length']];
        yield 'an object converted at the revision' => [['address.city']];
        yield 'nested resources' => [['items.*.title']];
        yield 'a resource' => [['customer.title']];
        yield 'an object with a down converter' => [['meta.a']];
        yield 'a list with a down converter' => [['lines.*.a']];
        yield 'a list through a key and a star' => [['tags.*.color', 'tags.name']];
    }

    /**
     * @param list<string> $paths
     */
    #[DataProvider('pathsKeepingTheFieldWhole')]
    public function test_a_path_into_a_field_whose_members_are_not_planned_one_by_one_keeps_it_whole(array $paths): void
    {
        $head = [
            'title' => Schema::string(),
            'tags' => Schema::list(Schema::object(['name' => Schema::string(), 'color' => Schema::string()])),
            'address' => Schema::object(['city' => Schema::string(), 'zip' => Schema::string()], 'address')
                ->was(ApiRevision::Winter25, schema: Schema::object(['line' => Schema::string()]), down: static function (mixed $address): array {
                    self::assertIsArray($address);
                    self::assertIsString($address['city']);
                    self::assertIsString($address['zip']);

                    return ['line' => "{$address['zip']} {$address['city']}"];
                }),
            'items' => Schema::list(Schema::resource(CountingDeclaration::class)),
            'customer' => Schema::resource(CountingDeclaration::class),
            'meta' => Schema::object(['a' => Schema::string(), 'b' => Schema::string()], 'meta')
                ->was(ApiRevision::Winter25, down: static fn(mixed $meta): mixed => $meta),
            'lines' => Schema::list(Schema::object(['a' => Schema::string(), 'b' => Schema::string()]))
                ->was(ApiRevision::Winter25, down: static fn(mixed $lines): mixed => $lines),
        ];
        $subject = [
            'title' => 'Lamp',
            'tags' => [['name' => 'gift', 'color' => 'red']],
            'address' => ['city' => 'Paris', 'zip' => '75001'],
            'items' => [['title' => 'Desk']],
            'customer' => ['title' => 'Ada'],
            'meta' => ['a' => 'x', 'b' => 'y'],
            'lines' => [['a' => 'x', 'b' => 'y']],
        ];
        $projector = new Projector();
        $shape = self::shape($head, ApiRevision::Genesis);
        $whole = $projector->plan($shape, $head)->run($subject);
        $key = explode('.', $paths[0])[0];

        self::assertSame([$key => $whole[$key]], $projector->plan($shape, $head, $paths)->run($subject));
    }

    /**
     * @param array<string, Descriptor<mixed>> $head
     */
    private static function shape(array $head, Revision $revision): Shape
    {
        return ShapeSelector::at($head, $revision, Direction::Down);
    }
}
