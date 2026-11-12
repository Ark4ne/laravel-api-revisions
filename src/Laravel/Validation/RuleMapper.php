<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel\Validation;

use Ark4ne\ApiRevisions\Definition\TypeDefinition;

/**
 * The Laravel rules a type implies (model.md §5, D62), one mapper per `Kind` in the registry of the `RuleCompiler`: the
 * kind and its constraints only, never the presence (`required`, `nullable`) nor the rules the author declared.
 */
interface RuleMapper
{
    /**
     * @return list<mixed>
     */
    public function rules(TypeDefinition $type): array;
}
