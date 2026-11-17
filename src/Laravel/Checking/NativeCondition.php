<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Checking;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;

/**
 * A warning per Laravel native condition (`MissingValue`, `MergeValue`) a `RevisionedResource` returns from
 * `toArray()` at compilation: honoured at render, but outside the shape, the export and the lock. Declare `->when()` / `->whenLoaded()` on a descriptor instead.
 */
final readonly class NativeCondition implements Check
{
    public const string CODE = 'native-condition';

    public function run(CheckContext $context): void
    {
        $class = $context->class;
        if ($class === null || !method_exists($class, 'revisionedNativeConditions')) {
            return;
        }
        /** @var list<array-key> $keys */
        $keys = $class::revisionedNativeConditions();
        foreach ($keys as $key) {
            $context->warning(
                self::CODE,
                (is_string($key) ? "'{$key}'" : 'A mergeWhen()') . ' (response) is a Laravel native condition, honoured at render but outside the shape, the export and the lock: declare ->when() / ->whenLoaded() on a descriptor.',
                is_string($key) ? $key : null,
            );
        }
    }
}
