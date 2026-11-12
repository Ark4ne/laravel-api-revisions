<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Compilation\ShapeSelector;
use Ark4ne\ApiRevisions\Definition\Direction;
use Ark4ne\ApiRevisions\Laravel\Validation\RuleCompiler;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Contracts\Validation\Factory;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\OrderInputDeclaration;
use Test\Fixtures\Declarations\StoreOrderDeclaration;

/**
 * The rules of a request shape run by Laravel's validator: errors under the names the client knows (model.md §7.1).
 */
final class ShapeRulesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->application()['config']->set('revisions.enum', ApiRevision::class);
    }

    public function test_the_compiler_is_a_singleton(): void
    {
        self::assertSame($this->application()->make(RuleCompiler::class), $this->application()->make(RuleCompiler::class));
    }

    public function test_an_old_client_is_validated_with_its_own_names(): void
    {
        $errors = $this->validate(OrderInputDeclaration::class, ApiRevision::Winter25, ['title' => 'Lamp', 'state' => 'lost', 'total' => 12, 'name' => 'Ada']);

        self::assertSame(['state', 'total'], array_keys($errors));
    }

    public function test_a_valid_old_input_passes(): void
    {
        self::assertSame([], $this->validate(OrderInputDeclaration::class, ApiRevision::Genesis, ['title' => 'Lamp', 'state' => 'paid', 'total' => 12.5, 'name' => 'Ada']));
    }

    public function test_the_extra_rules_of_a_conversion_and_the_nested_keys_are_validated(): void
    {
        $errors = $this->validate(StoreOrderDeclaration::class, ApiRevision::Winter25, ['title' => 'Lamp', 'total' => '12.5', 'customer' => ['name' => 'Ada']]);

        self::assertSame(['total', 'customer.full_name'], array_keys($errors));
    }

    public function test_an_optional_nested_resource_may_be_left_out_but_its_required_fields_hold_once_sent(): void
    {
        self::assertSame([], $this->validate(StoreOrderDeclaration::class, ApiRevision::Atlas, ['title' => 'Lamp', 'total' => 12, 'surname' => 'L']));
        self::assertSame(['customer.name'], array_keys($this->validate(StoreOrderDeclaration::class, ApiRevision::Atlas, ['title' => 'Lamp', 'total' => 12, 'surname' => 'L', 'customer' => []])));
        self::assertSame(['customer.name'], array_keys($this->validate(StoreOrderDeclaration::class, ApiRevision::Atlas, ['title' => 'Lamp', 'total' => 12, 'surname' => 'L', 'customer' => ['x' => 1]])));
    }

    public function test_the_children_of_a_null_item_are_not_required(): void
    {
        $head = ['lines' => Schema::list(Schema::object(['sku' => Schema::string()->required()])->nullable())];
        $rules = $this->application()->make(RuleCompiler::class)->compile(ShapeSelector::at($head, ApiRevision::Atlas, Direction::Up), $head);

        $errors = $this->application()->make(Factory::class)->make(['lines' => [null, ['sku' => 'A'], []]], $rules)->errors()->toArray();

        self::assertSame(['lines.2.sku'], array_keys($errors));
    }

    /**
     * @param class-string<\Ark4ne\ApiRevisions\Schema\Revisioned> $class
     * @param array<string, mixed> $input
     *
     * @return array<string, list<string>>
     */
    private function validate(string $class, Revision $revision, array $input): array
    {
        $shape = $this->application()->make(Shapes::class)->for($class, DeclarationKind::RequestBody, $revision);
        $rules = $this->application()->make(RuleCompiler::class)->compile($shape, $class::describeRevisioned(DeclarationKind::RequestBody, null));

        /** @var array<string, list<string>> */
        return $this->application()->make(Factory::class)->make($input, $rules)->errors()->toArray();
    }
}
