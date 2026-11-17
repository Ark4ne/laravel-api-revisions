<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Compilation\Probe;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Revisions\RevisionScope;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Upgrade\Upgrader;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Requests\ConflictingRequest;
use Test\Fixtures\Requests\HeadConflictRequest;
use Test\Fixtures\Requests\ListOrdersRequest;
use Test\Fixtures\Requests\StoreOrderRequest;

/**
 * A `FormRequest` validated in the client's revision and upgraded to the head.
 */
final class RevisionedRequestTest extends FeatureTestCase
{
    private const array GENESIS_ORDER = [
        'title' => 'Lamp',
        'state' => 'paid',
        'total' => 12.5,
        'qty' => 3.0,
        'address' => ['street' => '1 Main St', 'postcode' => '75001'],
        'name' => 'Ada Lovelace',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->application()['config']->set('revisions.enum', ApiRevision::class);
        $router = $this->application()->make(Router::class);
        $router->post('/orders', static fn(StoreOrderRequest $request): array => [
            'validated' => $request->validated(),
            'safe' => $request->safe()->all(),
            'only' => $request->safe(['title', 'status']),
            'status' => $request->validated('status'),
            'input' => $request->all(),
        ])->middleware('revision');
        $router->get('/orders', static fn(ListOrdersRequest $request): array => $request->validated())->middleware('revision');
        $router->post('/conflicts', static fn(ConflictingRequest $request): array => $request->validated())->middleware('revision');
        $router->post('/head-conflicts', static fn(HeadConflictRequest $request): array => $request->validated())->middleware('revision');
    }

    public function test_a_genesis_client_is_validated_with_its_own_names(): void
    {
        $response = $this->postJson('/orders', ['title' => 'Lamp', 'state' => 'lost', 'total' => 'ten'], ['Api-Revision' => 'genesis']);

        $response->assertUnprocessable();
        self::assertSame(['state', 'total', 'name'], array_keys($this->errors($response)));
    }

    public function test_the_controller_receives_the_head_input(): void
    {
        $response = $this->postJson('/orders', self::GENESIS_ORDER, ['Api-Revision' => 'genesis']);

        $head = [
            'title' => 'Lamp',
            'status' => 'paid',
            'total' => 1250,
            'quantity' => 3,
            'address' => ['street' => '1 Main St', 'zip' => '75001'],
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'priority' => 0,
        ];
        $response->assertOk();
        self::assertSame($head, $response->json('validated'));
        self::assertSame($head, $response->json('safe'));
        self::assertSame(['title' => 'Lamp', 'status' => 'paid'], $response->json('only'));
        self::assertSame('paid', $response->json('status'));
    }

    public function test_the_input_stays_as_sent(): void
    {
        $response = $this->postJson('/orders', self::GENESIS_ORDER, ['Api-Revision' => 'genesis']);

        self::assertSame(self::GENESIS_ORDER['state'], $response->json('input.state'));
        self::assertNull($response->json('input.status'));
    }

    public function test_a_head_client_sends_the_head_input(): void
    {
        $head = ['title' => 'Lamp', 'status' => 'paid', 'total' => 1250, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'priority' => 2];

        $this->postJson('/orders', $head, ['Api-Revision' => 'atlas'])->assertOk()->assertJsonPath('validated', $head);
    }

    public function test_an_invalid_enum_value_is_a_validation_error_on_the_old_key(): void
    {
        $response = $this->postJson('/orders', ['state' => 'lost'] + self::GENESIS_ORDER, ['Api-Revision' => 'winter-25']);

        $response->assertUnprocessable();
        self::assertArrayHasKey('state', $this->errors($response));
        self::assertArrayNotHasKey('status', $this->errors($response));
    }

    public function test_query_parameters_are_validated_and_upgraded(): void
    {
        $this->postJson('/orders?filter[state]=paid&limit=20', self::GENESIS_ORDER, ['Api-Revision' => 'genesis'])
            ->assertOk()
            ->assertJsonPath('validated.filter', ['status' => 'paid'])
            ->assertJsonPath('validated.per_page', '20');

        $response = $this->postJson('/orders?filter[state]=lost&limit=0', self::GENESIS_ORDER, ['Api-Revision' => 'genesis']);
        self::assertSame(['filter.state', 'limit'], array_keys($this->errors($response)));
    }

    public function test_an_input_an_up_closure_rejects_fails_with_its_message_on_the_client_key(): void
    {
        $response = $this->postJson('/orders', ['total' => 'ten'] + self::GENESIS_ORDER, ['Api-Revision' => 'winter-25']);

        $response->assertUnprocessable();
        self::assertSame(['total' => ['The total must be an amount.']], $this->errors($response));
    }

    public function test_a_value_a_converter_rejects_fails_with_the_message_of_the_in_rule_on_its_old_key(): void
    {
        $response = $this->postJson('/orders', ['qty' => 1.0e20] + self::GENESIS_ORDER, ['Api-Revision' => 'genesis']);

        $response->assertUnprocessable();
        // the attribute names of the request are honoured
        self::assertSame(['qty' => ['The selected number of items is invalid.']], $this->errors($response));
    }

    public function test_a_name_declared_in_the_body_and_the_query_at_the_client_revision_throws(): void
    {
        $this->withoutExceptionHandling();
        $this->postJson('/conflicts', ['status' => 'a', 'note' => 'b'], ['Api-Revision' => 'spring-26'])->assertOk()->assertExactJson(['status' => 'a', 'note' => 'b']);

        $this->expectExceptionObject(DefinitionException::declaredTwice(ConflictingRequest::class, 'status', ApiRevision::Winter25));

        $this->postJson('/conflicts', ['status' => 'a'], ['Api-Revision' => 'winter-25']);
    }

    public function test_a_head_key_both_declare_throws_for_every_client(): void
    {
        $this->withoutExceptionHandling();

        $this->expectExceptionObject(DefinitionException::declaredTwice(HeadConflictRequest::class, 'status', ApiRevision::Atlas));

        $this->postJson('/head-conflicts?state=a', ['status' => 'b'], ['Api-Revision' => 'genesis']);
    }

    public function test_a_validator_given_replaces_the_head_of_the_previous_one(): void
    {
        $request = ListOrdersRequest::createFrom(Request::create('/orders?per_page=5'));
        $request->setContainer($this->application());
        self::assertSame(['per_page' => '5'], $request->validated());

        $request->setValidator(ValidatorFacade::make(['per_page' => '7'], ['per_page' => ['string']]));

        self::assertSame(['per_page' => '7'], $request->validated());
    }

    public function test_the_application_after_hooks_run_before_the_upgrade(): void
    {
        $response = $this->getJson('/orders?limit=13', ['Api-Revision' => 'genesis']);

        self::assertSame(['limit' => ['Unlucky.']], $this->errors($response));
        $this->getJson('/orders?limit=12', ['Api-Revision' => 'genesis'])->assertOk()->assertExactJson(['per_page' => '12']);
    }

    public function test_validated_runs_the_validation_when_it_has_not_run(): void
    {
        $request = ListOrdersRequest::createFrom(Request::create('/orders?per_page=5'));
        $request->setContainer($this->application());

        self::assertSame(['per_page' => '5'], $request->validated());

        $invalid = StoreOrderRequest::createFrom(Request::create('/orders', 'POST'));
        $invalid->setContainer($this->application());
        $this->expectException(ValidationException::class);
        $invalid->safe();
    }

    public function test_a_failed_validation_skips_the_upgrade(): void
    {
        $request = StoreOrderRequest::createFrom(Request::create('/orders', 'POST'));
        $request->setContainer($this->application());

        $validator = (fn(): Validator => $this->getValidatorInstance())->call($request);

        self::assertTrue($validator->fails());
    }

    public function test_the_upgrade_is_registered_once_on_each_validator(): void
    {
        $request = StoreOrderRequest::createFrom(Request::create('/orders', 'POST', ['total' => 'ten'] + self::GENESIS_ORDER));
        $request->setContainer($this->application());
        $instance = static fn(): Validator => (fn(): Validator => $this->getValidatorInstance())->call($request);

        $errors = $this->application()->make(RevisionScope::class)->using(ApiRevision::Winter25, static function () use ($request, $instance): array {
            $validator = $instance();
            $instance();
            $validator->fails();
            $request->setValidator($given = ValidatorFacade::make(['total' => 'ten'], ['total' => ['string']]));
            self::assertSame($given, $instance());
            $given->fails();

            return [$validator->errors()->toArray(), $given->errors()->toArray()];
        });

        self::assertSame([['total' => ['The total must be an amount.']], ['total' => ['The total must be an amount.']]], $errors);
    }

    public function test_a_request_declares_its_body_and_its_query_parameters(): void
    {
        self::assertSame([DeclarationKind::RequestBody, DeclarationKind::RequestQuery], StoreOrderRequest::declarationKinds());
        self::assertSame(['filter', 'per_page'], array_keys(StoreOrderRequest::describeRevisioned(DeclarationKind::RequestQuery, new Probe(StoreOrderRequest::class))));
        self::assertSame(
            ['title', 'status', 'total', 'quantity', 'address', 'first_name', 'last_name', 'priority', 'name'],
            array_keys(StoreOrderRequest::describeRevisioned(DeclarationKind::RequestBody, new Probe(StoreOrderRequest::class))),
        );
        self::assertSame([], ListOrdersRequest::describeRevisioned(DeclarationKind::RequestBody, new Probe(ListOrdersRequest::class)));
    }

    public function test_the_upgrader_is_a_singleton(): void
    {
        self::assertSame($this->application()->make(Upgrader::class), $this->application()->make(Upgrader::class));
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *
     * @return array<string, list<string>>
     */
    private function errors(TestResponse $response): array
    {
        /** @var array<string, list<string>> */
        return $response->json('errors') ?? [];
    }
}
