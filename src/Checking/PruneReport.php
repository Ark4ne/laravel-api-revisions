<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\Revisioned;

/**
 * What `Prune` found for the retirement of `$revision`: the dead declarations, and the classes it could not build.
 */
final readonly class PruneReport
{
    /**
     * @param list<PruneCandidate> $candidates by class, kind, then order of declaration
     * @param array<class-string<Revisioned>, string> $failures the message of what each class threw
     */
    public function __construct(
        public Revision $revision,
        public array $candidates,
        public array $failures = [],
    ) {}
}
