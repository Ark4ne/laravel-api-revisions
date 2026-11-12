<?php

declare(strict_types=1);

namespace Test\Unit\Projection;

use Ark4ne\ApiRevisions\Projection\PlainSourceReader;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Test\Fixtures\Customer;
use Test\Fixtures\MagicSubject;
use Test\TestCase;

final class PlainSourceReaderTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, list<string>, mixed}>
     */
    public static function reads(): iterable
    {
        yield 'an array key' => [['title' => 'Book'], ['title'], 'Book'];
        yield 'an array key holding null' => [['title' => null], ['title'], null];
        yield 'a missing array key' => [[], ['title'], null];
        yield 'an array access offset' => [new ArrayObject(['title' => 'Book']), ['title'], 'Book'];
        yield 'a missing array access offset' => [new ArrayObject([]), ['title'], null];
        yield 'a public property' => [new Customer('Ada', 'Lovelace'), ['firstName'], 'Ada'];
        yield 'a missing property' => [new stdClass(), ['title'], null];
        yield 'a magic getter' => [new MagicSubject(['title' => 'Book']), ['title'], 'Book'];
        yield 'a dotted path' => [['customer' => new Customer('Ada', 'Lovelace')], ['customer', 'lastName'], 'Lovelace'];
        yield 'a path stopping at null' => [['customer' => null], ['customer', 'lastName'], null];
        yield 'a path through a scalar' => [['customer' => 'Ada'], ['customer', 'lastName'], null];
        yield 'a scalar subject' => ['Book', ['title'], null];
        yield 'a null subject' => [null, ['title'], null];
    }

    /**
     * @param non-empty-list<string> $path
     */
    #[DataProvider('reads')]
    public function test_a_key_or_dotted_path_is_read_on_the_subject(mixed $subject, array $path, mixed $expected): void
    {
        self::assertSame($expected, (new PlainSourceReader())->read($subject, $path));
    }

    public function test_a_magic_getter_is_called_without_isset_so_its_errors_show(): void
    {
        $subject = new MagicSubject();

        try {
            (new PlainSourceReader())->read($subject, ['title']);
            self::fail('The strict mode of the subject should throw.');
        } catch (RuntimeException $exception) {
            self::assertSame('Missing attribute title.', $exception->getMessage());
        }
        self::assertSame(['get:title'], $subject->calls);
    }

    public function test_a_public_property_is_read_before_the_magic_getter(): void
    {
        $subject = new MagicSubject(['plain' => 'magic']);

        self::assertSame('public', (new PlainSourceReader())->read($subject, ['plain']));
        self::assertSame([], $subject->calls);
    }

    public function test_reading_never_mutates_the_subject(): void
    {
        $object = new ArrayObject(['title' => 'Book']);

        (new PlainSourceReader())->read($object, ['missing']);
        (new PlainSourceReader())->has($object, ['missing']);

        self::assertSame(['title' => 'Book'], $object->getArrayCopy());
    }

    /**
     * @return iterable<string, array{mixed, list<string>, bool}>
     */
    public static function presences(): iterable
    {
        yield 'an array key' => [['title' => 'Book'], ['title'], true];
        yield 'an array key holding null' => [['title' => null], ['title'], true];
        yield 'a missing array key' => [[], ['title'], false];
        yield 'an array access offset' => [new ArrayObject(['title' => 'Book']), ['title'], true];
        yield 'a missing array access offset' => [new ArrayObject([]), ['title'], false];
        yield 'a public property' => [new Customer('Ada', 'Lovelace'), ['firstName'], true];
        yield 'a missing property' => [new stdClass(), ['title'], false];
        yield 'a magic attribute' => [new MagicSubject(['title' => 'Book']), ['title'], true];
        yield 'a missing magic attribute' => [new MagicSubject(), ['title'], false];
        yield 'a dotted path' => [['customer' => ['name' => 'Ada']], ['customer', 'name'], true];
        yield 'a dotted path missing its last key' => [['customer' => []], ['customer', 'name'], false];
        yield 'a dotted path through null' => [['customer' => null], ['customer', 'name'], false];
        yield 'a scalar subject' => ['Book', ['title'], false];
        yield 'a dotted path missing on a strict magic subject' => [new MagicSubject(), ['customer', 'name'], false];
        yield 'a dotted path on a magic subject' => [new MagicSubject(['customer' => ['name' => 'Ada']]), ['customer', 'name'], true];
    }

    /**
     * @param non-empty-list<string> $path
     */
    #[DataProvider('presences')]
    public function test_has_tells_whether_the_subject_holds_the_path(mixed $subject, array $path, bool $expected): void
    {
        self::assertSame($expected, (new PlainSourceReader())->has($subject, $path));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function peeks(): iterable
    {
        yield 'an array key' => [['title' => 'Book'], 'Book'];
        yield 'a missing array key' => [[], null];
        yield 'a public property' => [(object) ['title' => 'Book'], 'Book'];
        yield 'an array access offset' => [new ArrayObject(['title' => 'Book']), null];
        yield 'a scalar subject' => ['Book', null];
    }

    #[DataProvider('peeks')]
    public function test_peek_reads_only_what_the_subject_already_holds(mixed $subject, mixed $expected): void
    {
        self::assertSame($expected, (new PlainSourceReader())->peek($subject, 'title'));
    }

    public function test_peek_never_calls_a_magic_getter(): void
    {
        $subject = new MagicSubject(['title' => 'Book']);

        self::assertNull((new PlainSourceReader())->peek($subject, 'title'));
        self::assertSame('public', (new PlainSourceReader())->peek($subject, 'plain'));
        self::assertSame([], $subject->calls);
    }

    public function test_a_plain_subject_has_no_relation_but_its_keys(): void
    {
        $reader = new PlainSourceReader();

        self::assertTrue($reader->loaded(['customer' => ['address' => null]], ['customer', 'address']));
        self::assertFalse($reader->loaded(['customer' => []], ['customer', 'address']));
    }
}
