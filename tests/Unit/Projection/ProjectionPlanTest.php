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
            'notes' => Schema::string()->nullable()->when(static fn(?string $notes): bool => $notes !== null),
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

    /**
     * @param array<string, Descriptor<mixed>> $head
     */
    private static function shape(array $head, Revision $revision): Shape
    {
        return ShapeSelector::at($head, $revision, Direction::Down);
    }
}
