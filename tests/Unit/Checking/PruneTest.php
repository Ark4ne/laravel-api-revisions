<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\Prune;
use Ark4ne\ApiRevisions\Checking\PruneCandidate;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\InvalidDeclaration;
use Test\Fixtures\Declarations\PrunableDeclaration;
use Test\TestCase;

final class PruneTest extends TestCase
{
    public function test_prune_lists_the_declarations_serving_only_the_oldest_revision(): void
    {
        $report = (new Prune(ApiRevision::class))->run([PrunableDeclaration::class]);

        self::assertSame(ApiRevision::Genesis, $report->revision);
        self::assertSame([
            ['request-query', 'page', 'since'],
            ['request-body', 'legacy', 'until'],
            ['request-body', 'name', 'was'],
            ['request-body', 'meta.code', 'since'],
            ['request-body', 'code', 'since'],
            ['request-body', 'old', 'until'],
            ['request-body', 'address.zip', 'was'],
            ['request-body', 'lines.*.sku', 'since'],
            ['request-body', 'grouped', 'since'],
        ], array_map(static fn(PruneCandidate $candidate): array => [$candidate->kind->value, $candidate->path, $candidate->declaration->value], $report->candidates));
        foreach ($report->candidates as $candidate) {
            self::assertSame(PrunableDeclaration::class, $candidate->class);
            self::assertSame(ApiRevision::Genesis, $candidate->revision);
        }
        self::assertSame([], $report->failures);
    }

    public function test_each_candidate_is_located_at_its_field_key(): void
    {
        $report = (new Prune(ApiRevision::class))->run([PrunableDeclaration::class]);

        $file = (string) realpath(__DIR__ . '/../../Fixtures/Declarations/PrunableDeclaration.php');
        $located = [];
        foreach ($report->candidates as $candidate) {
            self::assertSame($file, $candidate->file);
            $located["{$candidate->kind->value} {$candidate->path}"] = $candidate->line;
        }
        $source = (string) file_get_contents($file);
        self::assertSame(self::line($source, "'page' =>"), $located['request-query page']);
        self::assertSame(self::line($source, "'name' => Schema::string()->was("), $located['request-body name']);
        self::assertSame(self::line($source, "'zip' =>"), $located['request-body address.zip']);
        self::assertSame(self::line($source, "'sku' =>"), $located['request-body lines.*.sku']);
    }

    public function test_a_class_whose_declaration_throws_is_reported_and_skipped(): void
    {
        $report = (new Prune(ApiRevision::class))->run([InvalidDeclaration::class, PrunableDeclaration::class]);

        self::assertArrayHasKey(InvalidDeclaration::class, $report->failures);
        self::assertNotSame('', $report->failures[InvalidDeclaration::class]);
        self::assertCount(9, $report->candidates);
    }

    public function test_nothing_is_listed_when_no_declaration_names_the_oldest_revision(): void
    {
        $report = (new Prune(ApiRevision::class))->run([CustomerDeclaration::class]);

        self::assertSame([], $report->candidates);
    }

    private static function line(string $source, string $needle): int
    {
        $offset = strpos($source, $needle);
        self::assertNotFalse($offset, $needle);

        return substr_count($source, "\n", 0, $offset) + 1;
    }
}
