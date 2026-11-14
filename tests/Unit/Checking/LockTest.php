<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\Lock;
use Ark4ne\ApiRevisions\Checking\LockedRevision;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Exceptions\LockException;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\PodcastDeclaration;
use Test\TemporaryDirectory;
use Test\TestCase;

final class LockTest extends TestCase
{
    use TemporaryDirectory;

    protected function tearDown(): void
    {
        CheckedDeclaration::reset();

        parent::tearDown();
    }

    public function test_nothing_is_locked_without_its_directory(): void
    {
        $lock = new Lock($this->temporary);

        self::assertSame([], $lock->locked());
        self::assertFalse($lock->has(ApiRevision::Genesis));
    }

    public function test_a_written_snapshot_is_read_back(): void
    {
        $lock = new Lock($this->temporary);
        $snapshot = new LockedRevision('winter-25', 'genesis', ['App\A' => ['response' => ['type' => 'object']]]);

        $lock->write($snapshot);

        self::assertSame($snapshot->toJson(), file_get_contents($this->temporary . '/winter-25.json'));
        self::assertEquals($snapshot, $lock->read('winter-25'));
        self::assertTrue($lock->has(ApiRevision::Winter25));
        self::assertFalse($lock->has(ApiRevision::Genesis));
    }

    public function test_a_snapshot_leaves_out_a_class_absent_at_its_revision(): void
    {
        $exporter = new JsonSchemaExporter(new Shapes());

        self::assertSame([CustomerDeclaration::class], array_keys(Lock::capture(ApiRevision::Winter25, [PodcastDeclaration::class, CustomerDeclaration::class], $exporter)->classes));
        self::assertSame([PodcastDeclaration::class, CustomerDeclaration::class], array_keys(Lock::capture(ApiRevision::Atlas, [PodcastDeclaration::class, CustomerDeclaration::class], $exporter)->classes));
    }

    public function test_the_locked_revisions_are_the_values_of_the_lock_files_sorted(): void
    {
        $lock = new Lock($this->temporary);
        $lock->write(new LockedRevision('winter-25', 'genesis', []));
        $lock->write(new LockedRevision('genesis', null, []));
        file_put_contents($this->temporary . '/README.md', 'not a lock');

        self::assertSame(['genesis', 'winter-25'], $lock->locked());
    }

    public function test_a_file_that_is_not_a_snapshot_is_refused(): void
    {
        mkdir($this->temporary);
        file_put_contents($this->temporary . '/genesis.json', '{');

        $this->expectExceptionObject(LockException::invalidFile($this->temporary . '/genesis.json'));

        (new Lock($this->temporary))->read('genesis');
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->expectExceptionObject(LockException::invalidFile($this->temporary . '/genesis.json'));

        (new Lock($this->temporary))->read('genesis');
    }

    public function test_a_directory_that_cannot_be_created_is_reported(): void
    {
        file_put_contents($this->temporary, 'a file');

        $this->expectExceptionObject(LockException::unwritable($this->temporary . '/genesis.json'));

        (new Lock($this->temporary))->write(new LockedRevision('genesis', null, []));
    }

    public function test_a_capture_holds_the_structure_of_every_kind_of_every_class_and_the_previous_revision(): void
    {
        CheckedDeclaration::$kinds = [DeclarationKind::Response, DeclarationKind::RequestBody];
        CheckedDeclaration::$describe = static fn(): array => ['title' => Schema::string()->describe('The title.')];
        $exporter = new JsonSchemaExporter(new Shapes());

        $snapshot = Lock::capture(ApiRevision::Winter25, [CountingDeclaration::class, CheckedDeclaration::class], $exporter);

        self::assertSame('winter-25', $snapshot->revision);
        self::assertSame('genesis', $snapshot->previous);
        self::assertSame([CountingDeclaration::class, CheckedDeclaration::class], array_keys($snapshot->classes));
        self::assertSame(
            ['response' => Lock::normalize($exporter->export(CheckedDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25)), 'request-body' => Lock::normalize($exporter->export(CheckedDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Winter25))],
            $snapshot->classes[CheckedDeclaration::class],
        );
        self::assertSame(['$schema' => JsonSchemaExporter::DIALECT, 'properties' => ['title' => ['type' => 'string']], 'required' => ['title'], 'type' => 'object'], $snapshot->classes[CheckedDeclaration::class]['response']);
    }

    public function test_the_oldest_revision_is_captured_without_previous(): void
    {
        self::assertNull(Lock::capture(ApiRevision::Genesis, [], new JsonSchemaExporter())->previous);
    }

    public function test_the_structure_drops_the_documentation_and_sorts_the_keys(): void
    {
        $document = [
            '$schema' => JsonSchemaExporter::DIALECT,
            'title' => 'App\Order',
            '$comment' => 'response at genesis (published 2024-01-01)',
            'type' => 'object',
            'properties' => [
                'description' => ['type' => 'string', 'description' => 'The description.', 'examples' => ['A']],
                'placed_at' => ['type' => 'string', 'pattern' => '^\d{4}$', '$comment' => 'PHP date format Y'],
                'lines' => ['type' => 'array', 'items' => ['description' => 'A line.', 'type' => 'integer'], 'deprecated' => true],
                'note' => ['anyOf' => [['$ref' => '#/$defs/App.Note', 'description' => 'A note.'], ['type' => 'null']]],
            ],
            'required' => ['placed_at', 'description'],
            '$defs' => ['App.Note' => ['type' => 'object', 'title' => 'App\Note', 'properties' => ['title' => ['type' => 'string']]]],
        ];

        self::assertSame(
            [
                '$defs' => ['App.Note' => ['properties' => ['title' => ['type' => 'string']], 'type' => 'object']],
                '$schema' => JsonSchemaExporter::DIALECT,
                'properties' => [
                    'description' => ['type' => 'string'],
                    'lines' => ['items' => ['type' => 'integer'], 'type' => 'array'],
                    'note' => ['anyOf' => [['$ref' => '#/$defs/App.Note'], ['type' => 'null']]],
                    'placed_at' => ['$comment' => 'PHP date format Y', 'pattern' => '^\d{4}$', 'type' => 'string'],
                ],
                'required' => ['placed_at', 'description'],
                'type' => 'object',
            ],
            Lock::normalize($document),
        );
    }
}
