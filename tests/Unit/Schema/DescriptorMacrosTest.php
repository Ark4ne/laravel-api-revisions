<?php

declare(strict_types=1);

namespace Test\Unit\Schema;

use Ark4ne\ApiRevisions\Compilation\ShapeSelector;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Schema\BoolSchema;
use Ark4ne\ApiRevisions\Schema\Descriptor;
use Ark4ne\ApiRevisions\Schema\DescriptorMacros;
use Ark4ne\ApiRevisions\Schema\IntSchema;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Schema\StringSchema;
use Ark4ne\ApiRevisions\Schema\TypeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Macros\FieldMacros;
use Test\Fixtures\MoneySchema;
use Test\TestCase;

final class DescriptorMacrosTest extends TestCase
{
    public function test_a_registered_macro_is_a_fluent_method_of_every_descriptor(): void
    {
        DescriptorMacros::register('documented', FieldMacros::documented(...));
        TypeRegistry::register('money', MoneySchema::class);

        self::assertTrue(DescriptorMacros::has('documented'));
        self::assertSame('Title', Schema::string()->documented('Title')->toType()->description);
        self::assertSame('Count', Schema::int()->nullable()->documented('Count')->toType()->description);
        self::assertSame('Items', Schema::list(Schema::string())->documented('Items')->toType()->description);
        self::assertSame('Total', Schema::money()->documented('Total')->toType()->description);
    }

    public function test_a_macro_receives_the_descriptor_then_the_arguments_given(): void
    {
        $received = [];
        DescriptorMacros::register('spy', static function (Descriptor $descriptor, mixed ...$arguments) use (&$received): Descriptor {
            $received[] = [$descriptor, $arguments];

            return $descriptor;
        });
        $descriptor = Schema::string();

        self::assertSame($descriptor, $descriptor->spy('a', 2, flag: true)); // @phpstan-ignore method.notFound (registered by this test only)
        self::assertSame([[$descriptor, ['a', 2, 'flag' => true]]], $received);
    }

    public function test_named_arguments_reach_the_macro_parameters(): void
    {
        DescriptorMacros::register('documented', FieldMacros::documented(...));

        $type = Schema::string()->documented(example: 'Lamp', description: 'Title')->toType();

        self::assertSame('Title', $type->description);
        self::assertSame(['Lamp'], $type->examples);
    }

    public function test_a_macro_built_with_the_public_modifiers_is_honoured_by_the_projection(): void
    {
        DescriptorMacros::register('whenFlag', FieldMacros::whenFlag(...));
        $head = ['title' => Schema::string(), 'notes' => Schema::string()->whenFlag('detailed')];
        $plan = (new Projector())->plan(ShapeSelector::at($head, ApiRevision::Genesis, Direction::Down), $head);

        self::assertTrue($head['notes']->isConditional());
        self::assertSame(['title' => 'Lamp', 'notes' => 'fragile'], $plan->run(['title' => 'Lamp', 'notes' => 'fragile', 'detailed' => true]));
        self::assertSame(['title' => 'Lamp'], $plan->run(['title' => 'Lamp', 'notes' => 'fragile']));
    }

    public function test_a_macro_leaves_the_receiver_unchanged(): void
    {
        DescriptorMacros::register('documented', FieldMacros::documented(...));
        $descriptor = Schema::string();

        $documented = $descriptor->documented('Title');

        self::assertNull($descriptor->toType()->description);
        self::assertNotSame($descriptor, $documented);
    }

    public function test_a_macro_must_return_an_instance_of_the_receivers_class(): void
    {
        DescriptorMacros::register('retyped', static fn(Descriptor $descriptor): IntSchema => Schema::int());

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("The descriptor macro 'retyped' must return an instance of " . StringSchema::class . ', got ' . IntSchema::class . '.');

        Schema::string()->retyped(); // @phpstan-ignore method.notFound (registered by this test only)
    }

    public function test_a_macro_returning_no_descriptor_is_refused(): void
    {
        DescriptorMacros::register('counted', static fn(Descriptor $descriptor): int => 1);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("The descriptor macro 'counted' must return an instance of " . StringSchema::class . ', got int.');

        Schema::string()->counted(); // @phpstan-ignore method.notFound (registered by this test only)
    }

    public function test_an_unregistered_macro_cannot_be_called(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('Call to undefined method ' . StringSchema::class . "::whenFlag(): register it with DescriptorMacros::register('whenFlag', …).");

        Schema::string()->whenFlag();
    }

    public function test_a_method_of_the_subclass_wins_over_a_macro_of_that_name(): void
    {
        DescriptorMacros::register('min', static fn(Descriptor $descriptor): Descriptor => $descriptor->describe('macro'));

        $string = Schema::string()->min(2);
        $bool = Schema::bool()->min(2); // @phpstan-ignore method.notFound (registered by this test only)

        self::assertNull($string->toType()->description);
        self::assertSame(2, $string->toType()->min);
        self::assertInstanceOf(BoolSchema::class, $bool);
        self::assertSame('macro', $bool->toType()->description);
    }

    public function test_registering_a_name_again_replaces_the_macro(): void
    {
        DescriptorMacros::register('documented', static fn(Descriptor $descriptor): Descriptor => $descriptor->describe('first'));
        DescriptorMacros::register('documented', static fn(Descriptor $descriptor): Descriptor => $descriptor->describe('second'));

        self::assertSame('second', Schema::string()->documented('ignored')->toType()->description);
    }

    public function test_names_are_case_sensitive(): void
    {
        DescriptorMacros::register('whenFlag', FieldMacros::whenFlag(...));

        self::assertFalse(DescriptorMacros::has('whenflag'));
        $this->expectException(DefinitionException::class);

        Schema::string()->whenflag(); // @phpstan-ignore method.notFound (registered by this test only)
    }

    public function test_flush_forgets_every_macro(): void
    {
        DescriptorMacros::register('whenFlag', FieldMacros::whenFlag(...));

        DescriptorMacros::flush();

        self::assertFalse(DescriptorMacros::has('whenFlag'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'a modifier' => ['when'];
        yield 'another modifier' => ['nullable'];
        yield 'an accessor' => ['toType'];
        yield 'the magic method' => ['__call'];
        yield 'a modifier in other case' => ['Nullable'];
        yield 'empty' => [''];
        yield 'a leading digit' => ['1when'];
        yield 'a dash' => ['when-flag'];
        yield 'a trailing newline' => ["whenFlag\n"];
    }

    #[DataProvider('invalidNames')]
    public function test_a_name_descriptor_cannot_serve_is_refused(string $name): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage("Invalid macro name '{$name}': a descriptor macro needs a PHP method name that Descriptor does not already declare.");

        DescriptorMacros::register($name, FieldMacros::whenFlag(...));
    }
}
