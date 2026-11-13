<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Checking\Fields;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;

/**
 * An error per `was()` older than the `since` of its field: a dead declaration (model.md §4.4 rule 3).
 */
final readonly class HistoryBeforeSince implements Check
{
    public const string CODE = 'history-before-since';

    public function run(CheckContext $context): void
    {
        foreach ($context->kinds() as $kind) {
            foreach (Fields::descriptors($context->descriptors($kind)) as $path => $descriptor) {
                $history = $descriptor->history();
                $bound = $history->lifetime->since;
                if ($bound === null) {
                    continue;
                }
                foreach ($history->changes as $change) {
                    if (RevisionOrder::compare($change->revision, $bound) < 0) {
                        $context->error(
                            self::CODE,
                            "The was({$change->revision->name}) of '{$path}' ({$kind->value}) is older than its since({$bound->name}): it describes no revision.",
                            $path,
                            $change->revision,
                        );
                    }
                }
            }
        }
    }
}
