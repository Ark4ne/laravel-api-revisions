<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Support\Diagnostic;
use Ark4ne\ApiRevisions\Testing\InteractsWithRevisions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema as SchemaBuilder;
use Illuminate\Testing\PendingCommand;
use Test\FeatureTestCase;
use Test\Fixtures\Recipes\ApiRevision;
use Test\Fixtures\Recipes\Enums\OrderStatus;
use Test\Fixtures\Recipes\Http\Controllers\OrderController;
use Test\Fixtures\Recipes\Http\Requests\StoreOrderRequest;
use Test\Fixtures\Recipes\Http\Resources\OrderResource;
use Test\Fixtures\Recipes\Models\Account;
use Test\Fixtures\Recipes\Models\Customer;
use Test\Fixtures\Recipes\Models\Order;
use Test\Fixtures\Recipes\Webhooks\OrderWebhooks;

/**
 * The recipes of the agent skill (skills/laravel-api-revisions/recipes.md) run: every snippet there is taken from
 * `tests/Fixtures/Recipes/` or from this test (`SkillTest`).
 */
final class RecipesTest extends FeatureTestCase
{
    use InteractsWithRevisions;

    private const array GENESIS = [
        'title' => 'Lamp',
        'paid' => true,
        'total' => 12.5,
        'lines' => [['sku' => 'L-1', 'qty' => 2]],
        'customer' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
        'name' => 'Ada Lovelace',
    ];

    private const array WINTER = [
        'title' => 'Lamp',
        'state' => 'paid',
        'total' => '12.50',
        'lines' => [['sku' => 'L-1', 'quantity' => 2]],
        'customer' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
        'name' => 'Ada Lovelace',
    ];

    private const array SPRING = [
        'title' => 'Lamp',
        'status' => 'paid',
        'total' => 1250,
        'lines' => [['sku' => 'L-1', 'quantity' => 2]],
        'customer' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'tags' => ['gift'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        /** @var array<string, mixed> $recipe */
        $recipe = require dirname(__DIR__) . '/Fixtures/Recipes/config/revisions.php';
        foreach ($recipe as $key => $value) {
            $config->set("revisions.{$key}", $value);
        }
        $config->set('revisions.discovery', ['paths' => [dirname(__DIR__) . '/Fixtures/Recipes'], 'classes' => []]);
        $config->set('revisions.strict_values', true);
        $config->set('queue.default', 'database');
        $config->set('queue.failed.driver', 'null');
        SchemaBuilder::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $router = $this->application()->make(Router::class);
        $router->group(['middleware' => 'revision'], static function (Router $router): void {
            $router->get('/orders/{order}', static fn(): OrderResource => OrderResource::make(self::order()));
            $router->post('/orders', [OrderController::class, 'store']);
            $router->post('/echo', static fn(StoreOrderRequest $request): array => ['body' => $request->validated()]);
        });
    }

    public function test_the_recipes_declare_consistent_revisions(): void
    {
        $check = $this->artisan('revision:check', ['--list' => true]);
        self::assertInstanceOf(PendingCommand::class, $check);
        $check->expectsOutputToContain(OrderResource::class)
            ->expectsOutputToContain(StoreOrderRequest::class)
            ->assertSuccessful();
        $diagnostics = $this->application()->make(Checker::class)->run();

        self::assertSame([], array_map(static fn(Diagnostic $diagnostic): string => "{$diagnostic->code} {$diagnostic->class} {$diagnostic->field}", iterator_to_array($diagnostics, false)));
    }

    public function test_the_order_resource_renders_each_revision(): void
    {
        foreach (['genesis' => self::GENESIS, 'winter-25' => self::WINTER, 'spring-26' => self::SPRING] as $revision => $expected) {
            $rendered = ApiRevision::using(ApiRevision::from($revision), static fn(): array => OrderResource::make(self::order())->resolve());

            self::assertSame($expected, $rendered, $revision);
        }
    }

    public function test_a_down_closure_receives_the_php_value_of_the_newer_shape(): void
    {
        $order = self::order(['status' => OrderStatus::Pending]);

        self::assertFalse(ApiRevision::using(ApiRevision::Genesis, static fn(): array => OrderResource::make($order)->resolve())['paid']);
        self::assertSame('pending', ApiRevision::using(ApiRevision::Winter25, static fn(): array => OrderResource::make($order)->resolve())['state']);
    }

    public function test_an_order_matches_the_schema_of_each_revision(): void
    {
        $this->withRevision(ApiRevision::Winter25)
            ->getJson('/orders/1')
            ->assertOk()
            ->assertMatchesRevisionSchema(OrderResource::class);

        $this->assertRendersAtEveryRevision(OrderResource::class, self::order());
        $this->assertRendersAtEveryRevision(OrderResource::class, self::order(customer: false));
    }

    public function test_an_older_request_is_upgraded_to_the_head(): void
    {
        $this->withRevision(ApiRevision::Genesis)
            ->postJson('/echo', ['title' => 'Lamp', 'paid' => true, 'total' => 12.5, 'name' => 'Ada Lovelace'])
            ->assertExactJson(['body' => ['title' => 'Lamp', 'status' => 'paid', 'total' => 1250, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'tags' => []]]);

        $this->withRevision(ApiRevision::Winter25)
            ->postJson('/echo?send_email=1', ['title' => 'Lamp', 'state' => 'cancelled', 'total' => '9.99', 'name' => 'Grace Hopper'])
            ->assertExactJson(['body' => ['title' => 'Lamp', 'status' => 'cancelled', 'total' => 999, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'tags' => [], 'notify' => '1']]);
    }

    public function test_an_older_request_is_validated_with_the_names_its_client_knows(): void
    {
        $this->withRevision(ApiRevision::Winter25)
            ->postJson('/echo', ['title' => 'Lamp', 'state' => 'lost', 'total' => '9.99'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['state', 'name'])
            ->assertJsonMissingValidationErrors(['status', 'first_name']);
    }

    public function test_the_controller_reads_the_head_and_keeps_the_old_behaviour_for_old_clients(): void
    {
        $this->withRevision(ApiRevision::Genesis)
            ->postJson('/orders', ['title' => 'Lamp', 'paid' => false, 'total' => 12.5, 'name' => 'Ada Lovelace'])
            ->assertStatus(200)
            ->assertExactJson(['data' => ['title' => 'Lamp', 'paid' => false, 'total' => 12.5, 'lines' => [], 'name' => 'Ada Lovelace']]);

        $this->withRevision(ApiRevision::Spring26)
            ->postJson('/orders', ['title' => 'Lamp', 'status' => 'paid', 'total' => 1250, 'first_name' => 'Ada', 'last_name' => 'Lovelace'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'paid');
    }

    public function test_an_account_pinned_to_a_revision_gets_it_without_a_header(): void
    {
        $this->actingAs(new Account(['api_revision' => ApiRevision::Genesis]))
            ->getJson('/orders/1')
            ->assertHeader('Api-Revision', 'genesis')
            ->assertExactJson(['data' => self::GENESIS]);

        $this->actingAs(new Account(['api_revision' => null]))
            ->getJson('/orders/1', ['Api-Revision' => 'winter-25'])
            ->assertExactJson(['data' => self::WINTER]);
    }

    public function test_a_webhook_is_rendered_in_the_revision_of_its_account(): void
    {
        Http::fake();
        $account = new Account(['api_revision' => ApiRevision::Winter25, 'webhook_url' => 'https://example.com/hooks']);

        (new OrderWebhooks())->sendNow($account, self::order());
        Http::assertSentCount(1);

        (new OrderWebhooks())->queue($account, self::order());
        Http::assertSentCount(1);
        self::assertSame(ApiRevision::Spring26, ApiRevision::current());
        $worker = $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);
        self::assertInstanceOf(PendingCommand::class, $worker);
        $worker->assertSuccessful()->run();

        $sent = array_map(static fn(array $record): array => [$record[0]->url(), $record[0]->data()], Http::recorded()->all());
        self::assertSame(array_fill(0, 2, ['https://example.com/hooks', ['type' => 'order.updated', 'data' => self::WINTER]]), $sent);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function order(array $attributes = [], bool $customer = true): Order
    {
        $order = new Order([
            'title' => 'Lamp',
            'status' => OrderStatus::Paid,
            'total_cents' => 1250,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'tags' => ['gift'],
            'lines' => [['sku' => 'L-1', 'quantity' => 2]],
            ...$attributes,
        ]);

        if ($customer) {
            $order->setRelation('customer', new Customer(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']));
        }

        return $order;
    }
}
