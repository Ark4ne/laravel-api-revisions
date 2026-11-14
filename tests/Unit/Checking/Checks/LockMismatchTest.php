<?php

declare(strict_types=1);

namespace Test\Unit\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Checks\LockMismatch;
use Ark4ne\ApiRevisions\Checking\LockedRevision;
use Ark4ne\ApiRevisions\Exceptions\LockException;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\TestCase;

final class LockMismatchTest extends TestCase
{
    use RunsLockChecks;

    private const string CHECKED = CheckedDeclaration::class;

    protected function setUp(): void
    {
        parent::setUp();

        CheckedDeclaration::$describe = static fn(): array => [
            'title' => Schema::string(),
            'total' => Schema::int()->was(ApiRevision::Genesis, name: 'amount'),
        ];
    }

    public function test_a_field_leaking_into_a_locked_revision_fails_the_check(): void
    {
        $this->lockRevisions([self::CHECKED], ApiRevision::Genesis, ApiRevision::Winter25);
        CheckedDeclaration::$describe = static fn(): array => [
            'title' => Schema::string(),
            'total' => Schema::int()->was(ApiRevision::Genesis, name: 'amount'),
            'note' => Schema::string(),
        ];

        self::assertSame(
            [
                ['lock-mismatch', self::CHECKED, 'note', 'genesis', "'note' (response) is new in the locked revision genesis: declare its since(), or lock genesis again."],
                ['lock-mismatch', self::CHECKED, 'note', 'winter-25', "'note' (response) is new in the locked revision winter-25: declare its since(), or lock winter-25 again."],
            ],
            self::runCheck(new LockMismatch($this->lock()), [self::CHECKED]),
        );
    }

    public function test_a_field_added_after_the_locked_revisions_or_a_new_documentation_passes(): void
    {
        $this->lockRevisions([self::CHECKED], ApiRevision::Genesis, ApiRevision::Winter25);
        CheckedDeclaration::$describe = static fn(): array => [
            'title' => Schema::string()->describe('The title.')->example('Book')->deprecated(),
            'total' => Schema::int()->was(ApiRevision::Genesis, name: 'amount'),
            'note' => Schema::string()->since(ApiRevision::Spring26),
        ];

        self::assertSame([], self::runCheck(new LockMismatch($this->lock()), [self::CHECKED]));
    }

    public function test_a_field_removed_or_changed_in_a_locked_revision_is_reported_under_its_head_key(): void
    {
        $this->lockRevisions([self::CHECKED], ApiRevision::Genesis);
        CheckedDeclaration::$describe = static fn(): array => [
            'total' => Schema::float()->was(ApiRevision::Genesis, name: 'amount'),
        ];

        self::assertSame(
            [
                ['lock-mismatch', self::CHECKED, 'total', 'genesis', "'amount' (response) differs from the locked revision genesis: lock genesis again if it is intended."],
                ['lock-mismatch', self::CHECKED, 'title', 'genesis', "'title' (response) is gone from the locked revision genesis: lock genesis again if it is intended."],
            ],
            self::runCheck(new LockMismatch($this->lock()), [self::CHECKED]),
        );
    }

    public function test_a_field_no_longer_required_differs_from_its_lock(): void
    {
        CheckedDeclaration::$kinds = [DeclarationKind::RequestBody];
        CheckedDeclaration::$describe = static fn(): array => ['title' => Schema::string()->required()];
        $this->lockRevisions([self::CHECKED], ApiRevision::Genesis);
        CheckedDeclaration::$describe = static fn(): array => ['title' => Schema::string()];

        self::assertSame(
            [['lock-mismatch', self::CHECKED, 'title', 'genesis', "'title' (request-body) differs from the locked revision genesis: lock genesis again if it is intended."]],
            self::runCheck(new LockMismatch($this->lock()), [self::CHECKED]),
        );
    }

    public function test_another_keyword_that_differs_is_reported_on_the_class(): void
    {
        $this->lockRevisions([self::CHECKED], ApiRevision::Genesis);
        $snapshot = $this->lock()->read('genesis');
        $schema = $snapshot->classes[self::CHECKED]['response'];
        $this->lock()->write(new LockedRevision('genesis', null, [self::CHECKED => ['response' => [...$schema, 'minProperties' => 1]]]));

        self::assertSame(
            [['lock-mismatch', self::CHECKED, null, 'genesis', "The keyword 'minProperties' of the response differs from the locked revision genesis: lock genesis again if it is intended."]],
            self::runCheck(new LockMismatch($this->lock()), [self::CHECKED]),
        );
    }

    public function test_a_class_or_a_kind_added_to_or_removed_from_a_locked_revision_is_reported(): void
    {
        $this->lockRevisions([self::CHECKED], ApiRevision::Genesis);
        CheckedDeclaration::$kinds = [DeclarationKind::RequestBody];

        self::assertSame(
            [
                ['lock-mismatch', CountingDeclaration::class, null, 'genesis', 'The class is new in the locked revision genesis: declare the since() of its fields, or lock genesis again.'],
                ['lock-mismatch', self::CHECKED, null, 'genesis', 'The request-body is new in the locked revision genesis: declare the since() of its fields, or lock genesis again.'],
                ['lock-mismatch', self::CHECKED, null, 'genesis', 'The response is gone from the locked revision genesis: lock genesis again if it is intended.'],
            ],
            self::runCheck(new LockMismatch($this->lock()), [CountingDeclaration::class, self::CHECKED]),
        );
    }

    public function test_a_locked_class_no_longer_discovered_is_reported_on_the_enum(): void
    {
        $this->lockRevisions([self::CHECKED, CountingDeclaration::class], ApiRevision::Genesis);

        self::assertSame(
            [['lock-mismatch', ApiRevision::class, null, 'genesis', CountingDeclaration::class . ' is gone from the locked revision genesis: lock genesis again if it is intended.']],
            self::runCheck(new LockMismatch($this->lock()), [self::CHECKED]),
        );
    }

    public function test_a_lock_file_that_cannot_be_read_is_reported_on_the_enum_once(): void
    {
        $this->lockRevisions([self::CHECKED], ApiRevision::Genesis);
        file_put_contents($this->temporary . '/winter-25.json', '{');

        self::assertSame(
            [['lock-mismatch', ApiRevision::class, null, 'winter-25', LockException::invalidFile($this->temporary . '/winter-25.json')->getMessage()]],
            self::runCheck(new LockMismatch($this->lock()), [self::CHECKED]),
        );
    }

    public function test_a_lock_of_a_revision_that_is_no_longer_a_case_is_left_to_lock_order_changed(): void
    {
        $this->lockRevisions([self::CHECKED], ApiRevision::Genesis);
        $this->lock()->write(new LockedRevision('autumn-24', null, []));

        self::assertSame([], self::runCheck(new LockMismatch($this->lock()), [self::CHECKED]));
    }

    public function test_nothing_locked_reports_nothing(): void
    {
        self::assertSame([], self::runCheck(new LockMismatch($this->lock()), [self::CHECKED]));
    }

    public function test_a_class_or_a_kind_without_field_at_a_locked_revision_is_not_new_there(): void
    {
        $this->lockRevisions([], ApiRevision::Genesis);
        CheckedDeclaration::$describe = static fn(): array => ['title' => Schema::string()->since(ApiRevision::Spring26)];

        self::assertSame([], self::runCheck(new LockMismatch($this->lock()), [self::CHECKED]));
    }

    public function test_a_nested_resource_that_does_not_build_is_left_to_its_own_build(): void
    {
        CheckedDeclaration::$describe = static fn(): array => ['counting' => Schema::resource(CountingDeclaration::class)];
        $this->lockRevisions([self::CHECKED], ApiRevision::Genesis);
        CountingDeclaration::$fail = true;

        try {
            $codes = array_map(static fn(array $diagnostic): string => $diagnostic[0] . ' ' . $diagnostic[1], self::runCheck(new LockMismatch($this->lock()), [self::CHECKED, CountingDeclaration::class]));
        } finally {
            CountingDeclaration::$fail = false;
        }

        self::assertSame(['definition-error ' . CountingDeclaration::class], $codes);
    }

    public function test_the_snapshots_are_read_once_per_run(): void
    {
        $this->lockRevisions([self::CHECKED], ApiRevision::Genesis);
        $check = new LockMismatch($this->lock());
        self::assertSame([], self::runCheck($check, [self::CHECKED]));

        CheckedDeclaration::$describe = static fn(): array => ['title' => Schema::int()];
        file_put_contents($this->temporary . '/genesis.json', '{');

        self::assertSame(
            [['lock-mismatch', ApiRevision::class, null, 'genesis', LockException::invalidFile($this->temporary . '/genesis.json')->getMessage()]],
            self::runCheck($check, [self::CHECKED]),
        );
    }
}
