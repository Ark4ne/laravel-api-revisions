<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Testing;

use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Exceptions\UnknownRevisionException;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\RevisionValue;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Testing\TestResponse;
use JsonException;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * Registers the `TestResponse` macro `assertMatchesRevisionSchema(string $class, ?Revision $revision = null)`
 * (model.md D28, D72) over the application of the running test: registered again by every test.
 */
final class TestResponseMacros
{
    public static function register(Application $app): void
    {
        TestResponse::macro('assertMatchesRevisionSchema', function (string $class, ?Revision $revision = null) use ($app): TestResponse {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            /** @var class-string<Revisioned> $class */
            TestResponseMacros::assert($app, $this, $class, $revision);

            return $this;
        });
    }

    /**
     * The body of the macro: the revision of the response header by default, the `data` of a wrapped body.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     * @param class-string<Revisioned> $class
     *
     * @internal
     */
    public static function assert(Application $app, TestResponse $response, string $class, ?Revision $revision = null): void
    {
        $config = $app->make(RevisionsConfig::class);
        if ($revision === null && !$config->revisioned) {
            // no enum configured (D80): no revision header, the one implicit revision
            $revision = RevisionOrder::latest($config->enum);
        }
        if ($revision === null) {
            try {
                $revision = RevisionValue::parse($config->enum, $response->headers->get($config->responseHeader));
            } catch (UnknownRevisionException) {
                $revision = null;
            }
            $revision ??= Assert::fail("The response has no or an unknown {$config->responseHeader} header: pass the revision to assertMatchesRevisionSchema().");
        }
        try {
            $document = json_decode((string) $response->baseResponse->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            Assert::fail("The response is not JSON ({$error->getMessage()}): it cannot match the schema of {$class}.");
        }
        if ($document instanceof stdClass && property_exists($document, 'data')) {
            $document = $document->data;
        }
        $exporter = new JsonSchemaExporter($app->make(Shapes::class), closed: true);
        (new RevisionSchema($exporter))->assertMatches($document, $class, $revision);
    }
}
