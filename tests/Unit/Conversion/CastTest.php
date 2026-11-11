<?php

declare(strict_types=1);

namespace Test\Unit\Conversion;

use Ark4ne\ApiRevisions\Conversion\Cast;
use Test\TestCase;

final class CastTest extends TestCase
{
    public function test_cast_round_rounds_half_up(): void
    {
        self::assertSame(3, Cast::round()->convert(2.5));
        self::assertSame(2, Cast::round()->convert(2.49));
        self::assertSame(-3, Cast::round()->convert(-2.5));
    }

    public function test_cast_floor_rounds_toward_negative_infinity(): void
    {
        self::assertSame(2, Cast::floor()->convert(2.9));
        self::assertSame(-3, Cast::floor()->convert(-2.1));
    }

    public function test_cast_ceil_rounds_toward_positive_infinity(): void
    {
        self::assertSame(3, Cast::ceil()->convert(2.1));
        self::assertSame(-2, Cast::ceil()->convert(-2.9));
    }
}
