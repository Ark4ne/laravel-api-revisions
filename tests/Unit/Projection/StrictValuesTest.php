<?php

declare(strict_types=1);

namespace Test\Unit\Projection;

use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Projection\StrictValues;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class StrictValuesTest extends TestCase
{
    public function test_a_value_error_is_thrown(): void
    {
        $error = ValueException::nullNotAllowed('object', 'total', ApiRevision::Atlas);

        $this->expectExceptionObject($error);

        (new StrictValues())->handle($error, 0);
    }
}
