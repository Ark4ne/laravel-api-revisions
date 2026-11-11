<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Definition\Kind;
use Ark4ne\ApiRevisions\Schema\RuleSchema;
use ArrayObject;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\Fixtures\Enums\OrderStatus;
use Test\Fixtures\Rules\PostcodeRule;
use Test\TestCase;

final class RuleSchemaTest extends TestCase
{
    public function test_a_rule_schema_is_any_without_description(): void
    {
        $type = (new RuleSchema(['array']))->toType();

        self::assertSame(Kind::Any, $type->kind);
        self::assertNull($type->description);
        self::assertSame([], $type->jsonSchema);
    }

    public function test_a_described_rule_schema_carries_its_description(): void
    {
        $type = (new RuleSchema(['array']))->describe('Free metadata')->toType();

        self::assertSame(Kind::Any, $type->kind);
        self::assertSame('Free metadata', $type->description);
    }

    public function test_a_rule_schema_keeps_its_rules_before_the_added_ones(): void
    {
        $postcode = new PostcodeRule();

        $schema = (new RuleSchema(['string', $postcode], 'address.postcode'))->rules('size:5');

        self::assertSame(['string', $postcode, 'size:5'], $schema->declaredRules());
        self::assertSame(['address', 'postcode'], $schema->source()->path);
    }

    public function test_a_rule_schema_drops_the_keys_of_its_rules(): void
    {
        self::assertSame(['array', 'min:1'], (new RuleSchema(['type' => 'array', 'size' => 'min:1']))->declaredRules());
    }

    public function test_a_rule_schema_without_source_reads_its_declared_key(): void
    {
        self::assertTrue((new RuleSchema([]))->source()->isDeclaredKey());
    }

    public function test_rules_describing_their_schema_enrich_the_export(): void
    {
        $schema = new RuleSchema([
            'string',
            new PostcodeRule(['pattern' => '^\d{5}$', 'minLength' => 5]),
            new PostcodeRule(['pattern' => '^\d{4,5}$']),
        ]);

        self::assertSame(['pattern' => '^\d{4,5}$', 'minLength' => 5], $schema->toType()->jsonSchema);
    }

    public function test_a_describing_rule_added_later_enriches_the_export_too(): void
    {
        $schema = (new RuleSchema(['string']))->rules(new PostcodeRule());

        self::assertSame(['pattern' => '^\d{5}$', 'examples' => ['75001']], $schema->toType()->jsonSchema);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function values(): iterable
    {
        yield 'string' => ['a'];
        yield 'int' => [12];
        yield 'float' => [1.5];
        yield 'bool' => [false];
        yield 'array' => [['a' => 1, 'b' => [2]]];
        yield 'object' => [new stdClass()];
        yield 'enum' => [OrderStatus::Paid];
        yield 'iterator' => [new ArrayObject([1, 2])];
        yield 'json serializable' => [new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['x' => 1];
            }
        }];
    }

    #[DataProvider('values')]
    public function test_a_rule_schema_normalizes_and_serializes_any_value_as_is(mixed $value): void
    {
        $schema = new RuleSchema([]);

        self::assertSame($value, $schema->normalize($value));
        self::assertSame($value, $schema->serialize($value));
    }

    public function test_a_rule_schema_refuses_null_like_every_descriptor(): void
    {
        self::assertRefused((new RuleSchema([]))->normalize(null), null, Kind::Any, null);
    }
}
