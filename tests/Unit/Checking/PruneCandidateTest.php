<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\PruneCandidate;
use Ark4ne\ApiRevisions\Checking\PrunedDeclaration;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\PrunableDeclaration;
use Test\TestCase;

final class PruneCandidateTest extends TestCase
{
    public function test_a_candidate_describes_the_field_what_to_delete_and_where(): void
    {
        $candidate = new PruneCandidate(PrunableDeclaration::class, DeclarationKind::RequestBody, 'address.zip', PrunedDeclaration::Was, ApiRevision::Genesis, '/app/Requests/Store.php', 12);

        self::assertSame("Test\\Fixtures\\Declarations\\PrunableDeclaration request-body 'address.zip': was(Genesis), delete the was() (/app/Requests/Store.php:12)", $candidate->describe());
    }

    public function test_a_candidate_names_its_file_relative_to_a_base_path(): void
    {
        $candidate = new PruneCandidate(PrunableDeclaration::class, DeclarationKind::Response, 'legacy', PrunedDeclaration::Until, ApiRevision::Genesis, '/app/src/Order.php', 3);

        self::assertStringEndsWith('(src/Order.php:3)', $candidate->describe('/app'));
        self::assertStringEndsWith('(src/Order.php:3)', $candidate->describe('/app/'));
        self::assertStringEndsWith('(/app/src/Order.php:3)', $candidate->describe('/elsewhere'));
    }

    public function test_a_candidate_without_line_names_its_file_and_one_without_file_nothing(): void
    {
        $file = new PruneCandidate(PrunableDeclaration::class, DeclarationKind::Response, 'legacy', PrunedDeclaration::Until, ApiRevision::Genesis, '/app/Order.php');
        $none = new PruneCandidate(PrunableDeclaration::class, DeclarationKind::Response, 'code', PrunedDeclaration::Since, ApiRevision::Genesis);

        self::assertStringEndsWith("'legacy': until(Genesis), delete the field (/app/Order.php)", $file->describe());
        self::assertStringEndsWith("'code': since(Genesis), delete the since(), already a no-op", $none->describe());
    }
}
