<?php

declare(strict_types=1);

namespace Test\Fixtures;

/**
 * A subclass of `PropertySubject`: the private property of its parent is not public to it either.
 */
final class ChildPropertySubject extends PropertySubject
{
    public string $child = 'child';
}
