<?php

declare(strict_types=1);

namespace Test\Unit\Projection;

use Ark4ne\ApiRevisions\Exceptions\ValueException;
use Ark4ne\ApiRevisions\Projection\LenientValues;
use Test\Fixtures\ApiRevision;
use Test\TestCase;

final class LenientValuesTest extends TestCase
{
    public function test_a_value_error_renders_its_fallback_and_is_reported(): void
    {
        $reported = [];
        $error = ValueException::refused('object', 'total', ApiRevision::Atlas, 'int', 'string');

        $value = (new LenientValues(static function (ValueException $error) use (&$reported): void {
            $reported[] = $error;
        }))->handle($error, 0);

        self::assertSame(0, $value);
        self::assertSame([$error], $reported);
    }

    public function test_without_reporter_a_value_error_renders_its_fallback(): void
    {
        self::assertNull((new LenientValues())->handle(ValueException::nullNotAllowed('object', 'total', ApiRevision::Atlas), null));
    }
}
