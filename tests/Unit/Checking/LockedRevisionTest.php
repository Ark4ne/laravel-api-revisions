<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\LockedRevision;
use Ark4ne\ApiRevisions\Exceptions\LockException;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class LockedRevisionTest extends TestCase
{
    private const string JSON = <<<'JSON'
        {
            "previous": "genesis",
            "classes": {
                "App\\A": {
                    "request-body": {
                        "type": "object"
                    },
                    "response": {
                        "properties": {
                            "url": {
                                "pattern": "^https://"
                            }
                        },
                        "type": "object"
                    }
                },
                "App\\B": {
                    "response": {
                        "type": "object"
                    }
                }
            }
        }

        JSON;

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'not JSON' => ['{'];
        yield 'not an object' => ['[]'];
        yield 'no previous' => ['{"classes": {}}'];
        yield 'a previous neither a string nor null' => ['{"previous": 1, "classes": {}}'];
        yield 'no classes' => ['{"previous": null}'];
        yield 'classes not an object' => ['{"previous": null, "classes": [1]}'];
        yield 'kinds not an object' => ['{"previous": null, "classes": {"A": [1]}}'];
        yield 'a schema not an object' => ['{"previous": null, "classes": {"A": {"response": 1}}}'];
    }

    public function test_the_json_is_pretty_printed_with_its_classes_and_kinds_sorted(): void
    {
        $locked = new LockedRevision('winter-25', 'genesis', [
            'App\B' => ['response' => ['type' => 'object']],
            'App\A' => [
                'response' => ['properties' => ['url' => ['pattern' => '^https://']], 'type' => 'object'],
                'request-body' => ['type' => 'object'],
            ],
        ]);

        self::assertSame(self::JSON, $locked->toJson());
    }

    public function test_the_json_is_read_back(): void
    {
        $locked = LockedRevision::fromJson(self::JSON, '/lock/winter-25.json');

        self::assertSame('winter-25', $locked->revision);
        self::assertSame('genesis', $locked->previous);
        self::assertSame(self::JSON, $locked->toJson());
    }

    public function test_the_oldest_revision_has_no_previous(): void
    {
        $locked = LockedRevision::fromJson('{"previous": null, "classes": {}}', '/lock/genesis.json');

        self::assertNull($locked->previous);
        self::assertSame([], $locked->classes);
        self::assertSame("{\n    \"previous\": null,\n    \"classes\": {}\n}\n", $locked->toJson());
    }

    #[DataProvider('invalid')]
    public function test_a_file_that_is_not_a_snapshot_is_refused(string $json): void
    {
        $this->expectExceptionObject(LockException::invalidFile('/lock/genesis.json'));

        LockedRevision::fromJson($json, '/lock/genesis.json');
    }

    public function test_an_empty_schema_is_written_as_an_object_and_a_list_keyword_as_a_list(): void
    {
        $locked = new LockedRevision('genesis', null, ['App\\A' => ['response' => [
            'properties' => ['any' => [], 'tags' => ['items' => [], 'type' => 'array']],
            'required' => ['any'],
            'anyOf' => [[], ['type' => 'null']],
            'enum' => [],
        ]]]);

        self::assertSame(
            '{"previous":null,"classes":{"App\\\\A":{"response":{"properties":{"any":{},"tags":{"items":{},"type":"array"}},"required":["any"],"anyOf":[{},{"type":"null"}],"enum":[]}}}}',
            json_encode(json_decode($locked->toJson(), flags: JSON_THROW_ON_ERROR), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
