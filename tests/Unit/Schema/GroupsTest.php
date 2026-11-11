<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Support\Undefined;
use stdClass;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

/**
 * Groups, through `Schema::since()` / `Schema::until()` (model.md §4.3, §4.4, D24).
 */
final class GroupsTest extends TestCase
{
    public function test_a_group_sets_since_on_every_member(): void
    {
        $notes = Schema::string()->nullable();

        $group = Schema::since(ApiRevision::Atlas, ['tags' => Schema::list(Schema::string()), 'notes' => $notes], default: []);

        self::assertSame(['tags', 'notes'], array_keys($group));
        foreach ($group as $member) {
            self::assertSame(ApiRevision::Atlas, $member->lifetime()->since);
            self::assertNull($member->lifetime()->until);
            self::assertSame([], $member->default());
            self::assertSame([], $member->repeatedBounds());
        }
        self::assertTrue($group['notes']->isNullable());
        self::assertNull($notes->lifetime()->since);
    }

    public function test_a_group_without_default_leaves_its_members_without_one(): void
    {
        $group = Schema::since(ApiRevision::Atlas, ['tags' => Schema::string()]);

        self::assertTrue(Undefined::is($group['tags']->default()));
    }

    public function test_a_group_sets_until_on_every_member(): void
    {
        $group = Schema::until(ApiRevision::Spring26, ['name' => Schema::string('full_name'), 'state' => Schema::string()->since(ApiRevision::Winter25, 'new')]);

        self::assertSame(['name', 'state'], array_keys($group));
        self::assertSame(ApiRevision::Spring26, $group['name']->lifetime()->until);
        self::assertSame(['full_name'], $group['name']->source()->path);
        self::assertSame(ApiRevision::Spring26, $group['state']->lifetime()->until);
        self::assertSame(ApiRevision::Winter25, $group['state']->lifetime()->since);
        self::assertSame('new', $group['state']->default());
        self::assertSame([], $group['state']->repeatedBounds());
    }

    public function test_a_member_newer_than_its_group_keeps_its_own_since(): void
    {
        $group = Schema::since(ApiRevision::Winter25, [
            'newer' => Schema::string()->since(ApiRevision::Atlas),
            'same' => Schema::string()->since(ApiRevision::Winter25),
        ]);

        self::assertSame(ApiRevision::Atlas, $group['newer']->lifetime()->since);
        self::assertSame(ApiRevision::Winter25, $group['same']->lifetime()->since);
        self::assertSame([], $group['newer']->repeatedBounds());
        self::assertSame([], $group['same']->repeatedBounds());
    }

    public function test_a_member_older_than_its_group_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(sprintf(
            "The field 'notes' cannot exist since %1\$s::Genesis in a group since %1\$s::Spring26: a member's since must be at or after its group's.",
            ApiRevision::class,
        ));

        Schema::since(ApiRevision::Spring26, ['tags' => Schema::string(), 'notes' => Schema::string()->since(ApiRevision::Genesis)]);
    }

    public function test_a_member_earlier_than_its_group_keeps_its_own_until(): void
    {
        $group = Schema::until(ApiRevision::Spring26, [
            'earlier' => Schema::string()->until(ApiRevision::Genesis),
            'same' => Schema::string()->until(ApiRevision::Spring26),
        ]);

        self::assertSame(ApiRevision::Genesis, $group['earlier']->lifetime()->until);
        self::assertSame(ApiRevision::Spring26, $group['same']->lifetime()->until);
        self::assertSame([], $group['earlier']->repeatedBounds());
    }

    public function test_a_member_later_than_its_group_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(sprintf(
            "The field 'name' cannot exist until %1\$s::Atlas in a group until %1\$s::Winter25: a member's until must be at or before its group's.",
            ApiRevision::class,
        ));

        Schema::until(ApiRevision::Winter25, ['name' => Schema::string()->until(ApiRevision::Atlas)]);
    }

    public function test_a_member_default_overrides_the_group_default(): void
    {
        $group = Schema::since(ApiRevision::Winter25, [
            'own' => Schema::int()->since(ApiRevision::Winter25, 1),
            'own null' => Schema::int()->since(ApiRevision::Atlas, null),
            'newer without default' => Schema::int()->since(ApiRevision::Atlas),
            'none' => Schema::int(),
        ], default: 0);

        self::assertSame(1, $group['own']->default());
        self::assertNull($group['own null']->default());
        self::assertSame(0, $group['newer without default']->default());
        self::assertSame(0, $group['none']->default());
    }

    public function test_a_group_since_after_a_member_until_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('its since is newer than its until');

        Schema::since(ApiRevision::Atlas, ['name' => Schema::string()->until(ApiRevision::Winter25)]);
    }

    public function test_a_group_until_before_a_member_since_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('its since is newer than its until');

        Schema::until(ApiRevision::Winter25, ['tags' => Schema::string()->since(ApiRevision::Atlas)]);
    }

    public function test_nested_groups_apply_from_the_inner_one(): void
    {
        $group = Schema::since(ApiRevision::Winter25, [
            'a' => Schema::string(),
            ...Schema::since(ApiRevision::Spring26, ['b' => Schema::string()]),
            ...Schema::until(ApiRevision::Spring26, ['c' => Schema::string()]),
        ]);

        self::assertSame(ApiRevision::Winter25, $group['a']->lifetime()->since);
        self::assertSame(ApiRevision::Spring26, $group['b']->lifetime()->since);
        self::assertSame(ApiRevision::Winter25, $group['c']->lifetime()->since);
        self::assertSame(ApiRevision::Spring26, $group['c']->lifetime()->until);
    }

    public function test_an_inner_group_older_than_the_outer_one_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("The field 'b' cannot exist since");

        Schema::since(ApiRevision::Spring26, [...Schema::since(ApiRevision::Winter25, ['b' => Schema::string()])]);
    }

    public function test_a_group_inside_an_object_works_the_same(): void
    {
        $object = Schema::object([
            'street' => Schema::string(),
            ...Schema::since(ApiRevision::Atlas, ['floor' => Schema::int()], default: 0),
        ]);

        $children = $object->children();
        self::assertSame(['street', 'floor'], array_keys($children));
        self::assertNull($children['street']->lifetime()->since);
        self::assertSame(ApiRevision::Atlas, $children['floor']->lifetime()->since);
        self::assertSame(0, $children['floor']->default());
    }

    public function test_a_member_that_is_not_a_descriptor_is_refused(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Invalid member 'total' of a group: a descriptor is expected, got stdClass.");

        Schema::until(ApiRevision::Atlas, ['title' => Schema::string(), 'total' => new stdClass()]); // @phpstan-ignore argument.type
    }

    public function test_a_member_without_string_key_is_refused(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Invalid member key 0 of a group: members are keyed by non-empty strings.');

        Schema::since(ApiRevision::Atlas, [Schema::string()]); // @phpstan-ignore argument.type
    }

    public function test_a_member_with_an_empty_key_is_refused(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Invalid member key '' of a group");

        Schema::since(ApiRevision::Atlas, ['' => Schema::string()]);
    }
}
