<?php

declare(strict_types=1);

namespace Test\Unit\Laravel\Http\Resolvers;

use Ark4ne\ApiRevisions\Exceptions\UnknownRevisionException;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\RevisionValue;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Revisions\IntRevision;
use Test\TestCase;

final class RevisionValueTest extends TestCase
{
    public function test_no_value_is_no_revision(): void
    {
        self::assertNull(RevisionValue::parse(ApiRevision::class, null));
        self::assertNull(RevisionValue::parse(ApiRevision::class, ''));
    }

    public function test_a_backed_value_gives_its_revision(): void
    {
        self::assertSame(ApiRevision::Spring26, RevisionValue::parse(ApiRevision::class, 'spring-26'));
        self::assertSame(IntRevision::First, RevisionValue::parse(IntRevision::class, '20'));
        self::assertSame(IntRevision::Second, RevisionValue::parse(IntRevision::class, 3));
    }

    public function test_a_revision_of_the_enum_is_kept(): void
    {
        self::assertSame(ApiRevision::Winter25, RevisionValue::parse(ApiRevision::class, ApiRevision::Winter25));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function unknownValues(): iterable
    {
        yield 'an unknown value' => ['2020-01', '2020-01'];
        yield 'a case name' => ['Atlas', 'Atlas'];
        yield 'surrounding blanks' => [' atlas', ' atlas'];
        yield 'an array' => [['atlas'], 'array'];
        yield 'an object' => [new stdClass(), 'stdClass'];
        yield 'a revision of another enum' => [IntRevision::First, IntRevision::class];
    }

    #[DataProvider('unknownValues')]
    public function test_a_value_naming_no_revision_is_unknown(mixed $value, string $named): void
    {
        try {
            RevisionValue::parse(ApiRevision::class, $value);
            self::fail('The value should be unknown.');
        } catch (UnknownRevisionException $exception) {
            self::assertSame($named, $exception->value);
            self::assertSame(['genesis', 'winter-25', 'spring-26', 'atlas'], $exception->accepted);
        }
    }

    public function test_the_accepted_values_of_an_int_backed_enum_are_strings_in_order(): void
    {
        try {
            RevisionValue::parse(IntRevision::class, 2);
            self::fail('The value should be unknown.');
        } catch (UnknownRevisionException $exception) {
            self::assertSame("Unknown API revision '2', expected one of: 20, 3, 1.", $exception->getMessage());
            self::assertSame('2', $exception->value);
            self::assertSame(['20', '3', '1'], $exception->accepted);
        }
    }
}
