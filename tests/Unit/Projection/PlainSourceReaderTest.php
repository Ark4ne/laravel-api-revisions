<?php

declare(strict_types=1);

namespace Test\Unit\Projection;

use Ark4ne\ApiRevisions\Projection\PlainSourceReader;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use ReflectionClass;
use RuntimeException;
use SimpleXMLElement;
use stdClass;
use Test\Fixtures\ChildPropertySubject;
use Test\Fixtures\Customer;
use Test\Fixtures\MagicSubject;
use Test\Fixtures\Php84\HookedSubject;
use Test\Fixtures\Php84\LazySubject;
use Test\Fixtures\PropertySubject;
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

    public function test_a_declared_public_property_is_read_and_held_without_the_magic_methods(): void
    {
        $reader = new PlainSourceReader();
        $subject = new PropertySubject();

        self::assertSame('Book', $reader->read($subject, ['title']));
        self::assertNull($reader->read($subject, ['notes']));
        self::assertTrue($reader->has($subject, ['title']));
        self::assertTrue($reader->has($subject, ['notes']));
        self::assertSame('Book', $reader->peek($subject, 'title'));
        self::assertSame([], $subject->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fallbacks(): iterable
    {
        yield 'an uninitialized typed property' => ['pending'];
        yield 'an unset property' => ['removed'];
        yield 'a static property' => ['shared'];
        yield 'a protected property' => ['secret'];
        yield 'a private property' => ['attributes'];
        yield 'an undeclared key' => ['other'];
    }

    #[DataProvider('fallbacks')]
    public function test_a_property_the_subject_does_not_expose_falls_back_to_the_magic_methods(string $key): void
    {
        $reader = new PlainSourceReader();
        $subject = new PropertySubject([$key => 'attribute']);

        self::assertSame('attribute', $reader->read($subject, [$key]));
        self::assertTrue($reader->has($subject, [$key]));
        self::assertFalse($reader->has(new PropertySubject(), [$key]));
        self::assertNull($reader->peek($subject, $key));
        self::assertSame(["get:{$key}", "isset:{$key}", "get:{$key}"], $subject->calls);
    }

    public function test_the_properties_of_the_parent_classes_are_told_apart_too(): void
    {
        $reader = new PlainSourceReader();
        $subject = new ChildPropertySubject(['attributes' => 'attribute']);

        self::assertSame('child', $reader->read($subject, ['child']));
        self::assertSame('Book', $reader->read($subject, ['title']));
        self::assertSame('attribute', $reader->read($subject, ['attributes']));
        self::assertSame(['get:attributes'], $subject->calls);
    }

    public function test_a_dynamic_property_is_read_and_held(): void
    {
        $reader = new PlainSourceReader();
        $subject = new PropertySubject();
        $subject->extra = 'dynamic'; // @phpstan-ignore property.notFound (a dynamic property)
        $object = new stdClass();
        $object->title = null;

        self::assertSame('dynamic', $reader->read($subject, ['extra']));
        self::assertTrue($reader->has($subject, ['extra']));
        self::assertSame('dynamic', $reader->peek($subject, 'extra'));
        self::assertTrue($reader->has($object, ['title']));
        self::assertNull($reader->read($object, ['title']));
        self::assertSame([], $subject->calls);
    }

    public function test_a_dynamic_property_beside_a_non_public_or_static_one_of_that_name_is_read(): void
    {
        $reader = new PlainSourceReader();
        $subject = new ChildPropertySubject();
        $subject->attributes = 'dynamic'; // @phpstan-ignore property.private (a dynamic property: the parent's is private)
        $static = new PropertySubject();

        self::assertSame('dynamic', $reader->read($subject, ['attributes']));
        self::assertTrue($reader->has($subject, ['attributes']));
        self::assertNull($reader->peek($static, 'shared'));
    }

    public function test_an_internal_class_reads_as_get_object_vars_lists_it(): void
    {
        $reader = new PlainSourceReader();
        $xml = simplexml_load_string('<order><title>Book</title></order>');
        $object = new ArrayObject(['title' => 'Book'], ArrayObject::ARRAY_AS_PROPS);

        self::assertInstanceOf(SimpleXMLElement::class, $xml);
        self::assertSame('Book', $reader->read($xml, ['title']));
        self::assertFalse($reader->has($xml, ['missing']));
        self::assertNull($reader->peek($object, 'title'));
        self::assertSame('Book', $reader->read($object, ['title']));
    }

    #[RequiresPhp('>= 8.4.0')]
    public function test_lazy_objects_are_read_as_initialized(): void
    {
        $reader = new PlainSourceReader();
        $class = new ReflectionClass(LazySubject::class);

        foreach ([1, 2] as $_) {
            $ghost = $class->newLazyGhost(static function (LazySubject $subject): void {
                $subject->title = 'ghost';
            });
            $proxy = $class->newLazyProxy(static function (): LazySubject {
                $subject = new LazySubject();
                $subject->title = 'proxy';

                return $subject;
            });

            self::assertSame('ghost', $reader->read($ghost, ['title']));
            self::assertSame('proxy', $reader->read($proxy, ['title']));
            self::assertSame('proxy', $reader->peek($proxy, 'title'));
            self::assertTrue($reader->has($proxy, ['title']));
        }
    }

    #[RequiresPhp('>= 8.4.0')]
    public function test_hooked_properties_are_read_as_get_object_vars_lists_them(): void
    {
        $reader = new PlainSourceReader();
        $subject = new HookedSubject();

        self::assertSame('hooked:raw', $reader->read($subject, ['backed']));
        self::assertTrue($reader->has($subject, ['backed']));
        self::assertSame('virtual', $reader->read($subject, ['virtual']));
        self::assertSame('virtual', $reader->peek($subject, 'virtual'));
        // a write-only property: whatever get_object_vars() lists for it (its listing varies across PHP versions)
        $listed = array_key_exists('writeOnly', get_object_vars($subject));
        self::assertSame($listed, $reader->has($subject, ['writeOnly']));
        self::assertSame($listed ? null : 'magic:writeOnly', $reader->read($subject, ['writeOnly']));
    }

    public function test_reading_never_builds_the_property_table_of_the_subject(): void
    {
        $reader = new PlainSourceReader();
        $subjects = [];
        for ($i = 0; $i < 1000; ++$i) {
            $subjects[] = new Customer('Ada', 'Lovelace');
        }
        $reader->read($subjects[0], ['firstName']);
        $before = memory_get_usage();

        foreach ($subjects as $subject) {
            $reader->read($subject, ['firstName']);
            $reader->has($subject, ['lastName']);
            $reader->peek($subject, 'firstName');
        }

        // get_object_vars() keeps a property table on each object read (hundreds of bytes)
        self::assertLessThan(64 * 1000, memory_get_usage() - $before);
    }
}
