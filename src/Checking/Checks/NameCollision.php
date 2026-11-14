<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Checking\Fields;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;

/**
 * An error per name written by two fields of one shape at a revision (model.md §4.4 rule 4), and per name a request
 * declares both in its body and in its query at a revision (Laravel validates them merged, D63).
 */
final readonly class NameCollision implements Check
{
    public const string CODE = 'name-collision';

    public function run(CheckContext $context): void
    {
        if ($context->class === null) {
            // the enum: its order() may be invalid, reported by the order checks
            return;
        }
        $kinds = $context->kinds();
        foreach ($context->order() as $revision) {
            foreach ($kinds as $kind) {
                foreach (Fields::shapes($context->shape($kind, $revision)) as $prefix => $shape) {
                    foreach ($shape->collisions as $collision) {
                        $name = $prefix === '' ? $collision->name : "{$prefix}.{$collision->name}";
                        $keys = implode(', ', array_map(static fn(string $key): string => "'{$key}'", $collision->headKeys));
                        $context->error(self::CODE, "'{$name}' is written by {$keys} at {$revision->name} ({$kind->value}).", $name, $revision);
                    }
                }
            }
            if (in_array(DeclarationKind::RequestBody, $kinds, true) && in_array(DeclarationKind::RequestQuery, $kinds, true)) {
                $body = $context->shape(DeclarationKind::RequestBody, $revision)->names();
                foreach (array_intersect($context->shape(DeclarationKind::RequestQuery, $revision)->names(), $body) as $name) {
                    $context->error(self::CODE, "'{$name}' is declared in both the body and the query at {$revision->name}: Laravel validates them merged.", $name, $revision);
                }
            }
        }
    }
}
