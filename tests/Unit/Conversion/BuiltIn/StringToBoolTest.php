<?php

declare(strict_types=1);

namespace Test\Unit\Conversion\BuiltIn;

use Ark4ne\ApiRevisions\Conversion\BuiltIn\StringToBool;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

final class StringToBoolTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'one' => ['1', true];
        yield 'true' => ['true', true];
        yield 'upper TRUE' => ['TRUE', true];
        yield 'on' => ['on', true];
        yield 'yes' => ['yes', true];
        yield 'zero' => ['0', false];
        yield 'false' => ['false', false];
        yield 'off' => ['Off', false];
        yield 'no' => ['no', false];
        yield 'empty' => ['', false];
        yield 'other' => ['maybe', true];
        yield 'blank, read as filter_var' => [' ', false];
        yield 'surrounding blanks ignored' => [' yes ', true];
    }

    #[DataProvider('conversions')]
    public function test_it_converts_with_the_semantics_of_the_model(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, (new StringToBool())->convert($value));
    }

    public function test_it_declares_its_loss_and_its_rules(): void
    {
        self::assertFalse((new StringToBool())->lossless());
        self::assertSame([], (new StringToBool())->extraRules());
    }
}
