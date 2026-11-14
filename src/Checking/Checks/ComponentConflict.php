<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;

/**
 * `component-conflict` (model.md §9, D70): two uses of one component name with different bodies in the document of a
 * kind at a revision, on the field of the second use; reported once per kind and field, at the newest revision (the
 * first conflict of a document stops its export: the next one shows once it is fixed).
 */
final readonly class ComponentConflict implements Check
{
    public const string CODE = 'component-conflict';

    public function run(CheckContext $context): void
    {
        if ($context->class === null) {
            return;
        }
        $reported = [];
        foreach ($context->kinds() as $kind) {
            foreach (array_reverse($context->order()) as $revision) {
                try {
                    $context->export($kind, $revision);
                } catch (DefinitionException $error) {
                    $field = (string) $error->field;
                    if ($error->checkCode !== self::CODE || isset($reported[$kind->value][$field])) {
                        continue;
                    }
                    $reported[$kind->value][$field] = true;
                    $context->error(self::CODE, $error->getMessage(), $field, $revision);
                }
            }
        }
    }
}
