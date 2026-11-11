<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\Source;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\TestCase;

final class SourceTest extends TestCase
{
    public function test_the_declared_key_has_no_path_and_no_closure(): void
    {
        $source = Source::declaredKey();

        self::assertTrue($source->isDeclaredKey());
        self::assertFalse($source->isClosure());
        self::assertSame([], $source->path);
        self::assertNull($source->closure);
    }

    public function test_the_parent_subject_is_neither_a_key_nor_a_closure(): void
    {
        $source = Source::parent();

        self::assertTrue($source->isParent());
        self::assertFalse($source->isDeclaredKey());
        self::assertFalse($source->isClosure());
        self::assertFalse(Source::declaredKey()->isParent());
        self::assertFalse(Source::key('a')->isParent());
    }

    public function test_from_keeps_a_source_as_it_is(): void
    {
        $source = Source::parent();

        self::assertSame($source, Source::from($source));
    }

    public function test_a_key_is_a_path_of_one_segment(): void
    {
        $source = Source::key('reference');

        self::assertFalse($source->isDeclaredKey());
        self::assertSame(['reference'], $source->path);
    }

    public function test_a_dotted_key_is_read_segment_by_segment(): void
    {
        self::assertSame(['customer', 'name'], Source::key('customer.name')->path);
    }

    public function test_a_closure_source_keeps_the_closure_without_calling_it(): void
    {
        $closure = static fn(): never => throw new LogicException('A source must never run at declaration.');

        $source = Source::closure($closure);

        self::assertTrue($source->isClosure());
        self::assertFalse($source->isDeclaredKey());
        self::assertSame($closure, $source->closure);
    }

    public function test_from_maps_null_strings_and_closures(): void
    {
        $closure = static fn(): string => 'x';

        self::assertTrue(Source::from(null)->isDeclaredKey());
        self::assertSame(['a', 'b'], Source::from('a.b')->path);
        self::assertSame($closure, Source::from($closure)->closure);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function evaluatedValues(): iterable
    {
        yield 'int' => [12, 'int'];
        yield 'float' => [12.5, 'float'];
        yield 'bool' => [true, 'bool'];
        yield 'array' => [['a'], 'array'];
        yield 'object' => [new stdClass(), 'stdClass'];
    }

    #[DataProvider('evaluatedValues')]
    public function test_an_evaluated_value_is_refused_as_source(mixed $value, string $type): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("A source is a key, a dotted path or a closure, never an evaluated value: got {$type}.");

        Source::from($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'empty segment' => ['customer..name'];
        yield 'leading dot' => ['.name'];
        yield 'trailing dot' => ['customer.'];
    }

    #[DataProvider('invalidKeys')]
    public function test_a_key_with_an_empty_segment_is_refused(string $key): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Invalid source key '{$key}': a key or each segment of a dotted path must not be empty.");

        Source::key($key);
    }
}
