<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Checking\Checks\LockOrderChanged;
use Ark4ne\ApiRevisions\Checking\LockedRevision;
use Ark4ne\ApiRevisions\Support\Diagnostic;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\Fixtures\ListedClasses;
use Test\Fixtures\Revisions\MissingCaseRevision;
use Test\TestCase;

final class LockOrderChangedTest extends TestCase
{
    use RunsLockChecks;

    public function test_moving_a_locked_revision_fails_the_check(): void
    {
        // locked when winter-25 came first; order() now puts genesis before it
        $this->lock()->write(new LockedRevision('winter-25', null, []));
        $this->lock()->write(new LockedRevision('spring-26', 'genesis', []));

        self::assertSame(
            [
                ['lock-order-changed', ApiRevision::class, null, 'winter-25', 'The locked revision winter-25 was the oldest in order(), it now follows genesis: a published revision never moves.'],
                ['lock-order-changed', ApiRevision::class, null, 'spring-26', 'The locked revision spring-26 followed genesis in order(), it now follows winter-25: a published revision never moves.'],
            ],
            self::runCheck(new LockOrderChanged($this->lock()), [CountingDeclaration::class]),
        );
    }

    public function test_a_revision_inserted_before_the_oldest_locked_one_moves_it(): void
    {
        $this->lock()->write(new LockedRevision('genesis', 'alpha', []));

        self::assertSame(
            [['lock-order-changed', ApiRevision::class, null, 'genesis', 'The locked revision genesis followed alpha in order(), it is now the oldest: a published revision never moves.']],
            self::runCheck(new LockOrderChanged($this->lock()), []),
        );
    }

    public function test_a_locked_revision_that_is_no_longer_a_case_fails_the_check(): void
    {
        $this->lock()->write(new LockedRevision('autumn-24', null, []));

        self::assertSame(
            [['lock-order-changed', ApiRevision::class, null, null, 'The locked revision autumn-24 is no longer a case of ' . ApiRevision::class . ': a published revision never moves.']],
            self::runCheck(new LockOrderChanged($this->lock()), []),
        );
    }

    public function test_locked_revisions_in_their_place_pass(): void
    {
        $this->lockRevisions([CountingDeclaration::class], ApiRevision::Genesis, ApiRevision::Winter25, ApiRevision::Spring26);
        file_put_contents($this->temporary . '/atlas.json', '{');

        self::assertSame([], self::runCheck(new LockOrderChanged($this->lock()), [CountingDeclaration::class]));
    }

    public function test_an_invalid_order_is_left_to_the_order_checks(): void
    {
        $this->lock()->write(new LockedRevision('first', null, []));

        $codes = array_map(
            static fn(Diagnostic $diagnostic): string => $diagnostic->code,
            (new Checker(MissingCaseRevision::class, new ListedClasses([]), [...Checker::builtIn(), new LockOrderChanged($this->lock())]))->run()->all(),
        );

        self::assertSame(['order-missing-case'], $codes);
    }
}
