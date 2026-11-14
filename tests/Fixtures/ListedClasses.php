<?php

declare(strict_types=1);

namespace Test\Fixtures;

use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Schema\Revisioned;

/**
 * A class finder giving the classes it is built with.
 */
final readonly class ListedClasses implements ClassFinder
{
    /**
     * @param list<class-string<Revisioned>> $classes
     */
    public function __construct(private array $classes) {}

    public function find(): array
    {
        return $this->classes;
    }
}
