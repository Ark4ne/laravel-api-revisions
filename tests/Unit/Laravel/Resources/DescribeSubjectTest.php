<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Resources;

use Ark4ne\ApiRevisions\Laravel\Resources\DescribeSubject;
use Test\TestCase;

final class DescribeSubjectTest extends TestCase
{
    public function test_every_read_gives_null_and_nothing_throws(): void
    {
        $subject = new DescribeSubject();
        $subject->__set('title', 'ignored');
        $subject->offsetSet('title', 'ignored');
        $subject->__unset('title');
        $subject->offsetUnset('title');

        self::assertNull($subject->__get('title'));
        self::assertFalse($subject->__isset('title'));
        self::assertNull($subject->__call('fullName', []));
        self::assertNull($subject->offsetGet('title'));
        self::assertFalse($subject->offsetExists('title'));
        self::assertCount(0, $subject);
        self::assertSame([], iterator_to_array($subject));
        self::assertSame('', (string) $subject);
    }

    public function test_it_has_no_attribute_and_no_relation_loaded(): void
    {
        $subject = new DescribeSubject();

        self::assertSame([], $subject->getAttributes());
        self::assertFalse($subject->relationLoaded('customer'));
    }
}
