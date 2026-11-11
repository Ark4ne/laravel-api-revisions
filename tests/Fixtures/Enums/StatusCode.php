<?php

declare(strict_types=1);

namespace Test\Fixtures\Enums;

/**
 * A string-backed enum whose values are digits: an int reads as one of them (model.md D41).
 */
enum StatusCode: string
{
    case Ok = '200';
    case NotFound = '404';
}
