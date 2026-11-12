<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Conversion\ClosureConverter;
use Ark4ne\ApiRevisions\Definition\Lifetime;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\Change;
use Ark4ne\ApiRevisions\Schema\DateSchema;
use Ark4ne\ApiRevisions\Schema\History;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\IntRevision;
use Test\TestCase;

final class HistoryTest extends TestCase
{
    public function test_no_history_is_every_revision_without_change(): void
    {
        self::assertEquals(Lifetime::always(), History::none()->lifetime);
        self::assertSame([], History::none()->changes);
    }

    public function test_a_history_is_empty_without_bound_nor_change(): void
    {
        self::assertTrue(History::none()->isEmpty());
        self::assertFalse(History::none()->withLifetime(Lifetime::always()->withSince(ApiRevision::Genesis))->isEmpty());
        self::assertFalse(History::none()->withLifetime(Lifetime::always()->withUntil(ApiRevision::Spring26))->isEmpty());
        self::assertFalse(History::none()->withChange(new Change(ApiRevision::Genesis))->isEmpty());
    }

    public function test_changes_are_kept_newest_first_whatever_the_order_they_come_in(): void
    {
        $genesis = new Change(ApiRevision::Genesis, name: 'state');
        $spring = new Change(ApiRevision::Spring26, name: 'status_code');
        $winter = new Change(ApiRevision::Winter25);

        $history = History::none()->withChange($genesis)->withChange($spring)->withChange($winter);

        self::assertSame([$spring, $winter, $genesis], $history->changes);
    }

    public function test_the_lifetime_and_the_changes_are_kept_apart(): void
    {
        $change = new Change(ApiRevision::Winter25, name: 'state');
        $lifetime = Lifetime::always()->withSince(ApiRevision::Genesis);
        $none = History::none();

        $history = $none->withChange($change)->withLifetime($lifetime);

        self::assertSame($lifetime, $history->lifetime);
        self::assertSame([$change], $history->changes);
        self::assertSame([], $none->changes);
        self::assertEquals(Lifetime::always(), $none->lifetime);
    }

    public function test_a_change_on_the_head_throws(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(sprintf('Invalid was(%s::Atlas): the head has no older shape.', ApiRevision::class));

        History::none()->withChange(new Change(ApiRevision::Atlas, name: 'state'));
    }

    public function test_two_changes_on_the_same_revision_throw(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage(sprintf('Invalid was(%s::Winter25): this revision already has a was().', ApiRevision::class));

        History::none()->withChange(new Change(ApiRevision::Winter25, name: 'state'))->withChange(new Change(ApiRevision::Winter25));
    }

    public function test_changes_of_two_enums_cannot_be_mixed(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Revisions of two enums cannot be mixed');

        History::none()->withChange(new Change(ApiRevision::Winter25))->withChange(new Change(IntRevision::First));
    }

    public function test_configured_resolves_the_schema_of_every_change_and_keeps_the_rest(): void
    {
        $down = new ClosureConverter(static fn(): string => '');
        $history = History::none()
            ->withLifetime(Lifetime::always()->withUntil(ApiRevision::Spring26))
            ->withChange(new Change(ApiRevision::Winter25, 'placed', new DateSchema(), $down))
            ->withChange(new Change(ApiRevision::Genesis, 'day'));

        $configured = $history->configured(new Configuration(dateFormat: 'd/m/Y'));

        self::assertSame($history->lifetime, $configured->lifetime);
        self::assertSame('d/m/Y', $configured->changes[0]->schema?->toType()->format);
        self::assertSame('placed', $configured->changes[0]->name);
        self::assertSame(ApiRevision::Winter25, $configured->changes[0]->revision);
        self::assertSame($down, $configured->changes[0]->down);
        self::assertSame($history->changes[1], $configured->changes[1]);
    }
}
