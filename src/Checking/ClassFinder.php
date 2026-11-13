<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking;

use Ark4ne\ApiRevisions\Schema\Revisioned;

/**
 * Finds the declaring classes the check runs on (model.md §9, D9). The Laravel layer implements it over
 * `discovery.paths` and `discovery.classes` (`Laravel\Discovery\PathClassFinder`).
 */
interface ClassFinder
{
    /**
     * Every declaring class found, each once.
     *
     * @return list<class-string<Revisioned>>
     */
    public function find(): array;
}
