<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Checking\Fields;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Support\Undefined;

/**
 * An error per `required()` field of a request added after the oldest revision without default for the older clients
 * (model.md §7.3); none under `missing_default: null`, which gives them `null` (D47).
 */
final readonly class RequiredSinceWithoutDefault implements Check
{
    public const string CODE = 'required-since-without-default';

    public function run(CheckContext $context): void
    {
        if ($context->class === null) {
            // the enum: its order() may be invalid, reported by the order checks
            return;
        }
        $order = $context->order();
        $reported = [];
        foreach ($context->kinds() as $kind) {
            if ($kind->direction() !== Direction::Up) {
                continue;
            }
            // every revision: a field retired before the head is still added for the clients older than its since
            foreach ($order as $revision) {
                foreach (Fields::of($context->shape($kind, $revision)) as $path => $field) {
                    $since = $field->lifetime->since;
                    if (isset($reported["{$kind->value}@{$path}"]) || !$field->required || $since === null || $since === $order[0] || !Undefined::is($field->default)) {
                        continue;
                    }
                    $reported["{$kind->value}@{$path}"] = true;
                    $context->error(
                        self::CODE,
                        "'{$path}' ({$kind->value}) is required since {$since->name} without default: older clients never send it; give since({$since->name}, default: ...).",
                        $path,
                        $since,
                    );
                }
            }
        }
    }
}
