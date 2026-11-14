<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Testing;

use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\HeaderResolver;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\PathResolver;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\QueryResolver;
use Ark4ne\ApiRevisions\Laravel\RevisionsConfig;
use Ark4ne\ApiRevisions\Projection\Projector;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Uri;
use LogicException;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * Helpers for the `TestCase` of an application (model.md D28), as Laravel's `InteractsWithDatabase`: used on a Laravel
 * test case, whose `$app` holds the application.
 *
 * @property \Illuminate\Contracts\Foundation\Application|null $app
 */
trait InteractsWithRevisions
{
    /**
     * The revision `withRevision()` adds to the query of every request, in query mode.
     *
     * @var array{string, string}|null the parameter and the backed value
     */
    private ?array $revisionQuery = null;

    /**
     * Registers the `TestResponse` macro `assertMatchesRevisionSchema()`: called by Laravel's test case before each test.
     */
    public function setUpInteractsWithRevisions(): void
    {
        $this->revisionQuery = null;
        TestResponseMacros::register($this->revisionsApplication('assertMatchesRevisionSchema()'));
    }

    /**
     * Sends `$revision` with the next requests of the test, as the first resolver of the chain that can carry it reads
     * it (model.md D72): a header, the route parameter of path mode (`URL::defaults()`, for `route()`), or the query.
     */
    public function withRevision(Revision $revision): static
    {
        $app = $this->revisionsApplication('withRevision()');
        $config = $app->make(RevisionsConfig::class);
        if (!$config->revisioned) {
            throw new LogicException('withRevision() sends a revision the API reads: set revisions.enum to the revision enum of the API first.');
        }
        $value = (string) $revision->value;
        foreach ($config->resolvers as [$class, $options]) {
            if ($class === HeaderResolver::class) {
                $this->withHeader(self::revisionOption($options, 'HeaderResolver', HeaderResolver::DEFAULT_NAME), $value);

                return $this;
            }
            if ($class === PathResolver::class) {
                $app->make(UrlGenerator::class)->defaults([$config->pathParameter() => $value]);

                return $this;
            }
            if ($class === QueryResolver::class) {
                $this->revisionQuery = [self::revisionOption($options, 'QueryResolver', QueryResolver::DEFAULT_NAME), $value];

                return $this;
            }
        }

        throw new LogicException('withRevision() sends a revision through a HeaderResolver, a PathResolver or a QueryResolver: none is in revisions.resolvers.');
    }

    /**
     * Projects the response of `$class` on `$subject` at each revision (every one by default) and fails unless each
     * projection matches the schema of its revision, objects closed (model.md D72).
     *
     * @param class-string<Revisioned> $class
     * @param list<Revision>|null $revisions
     */
    public function assertRendersAtEveryRevision(string $class, mixed $subject, ?array $revisions = null): void
    {
        $app = $this->revisionsApplication('assertRendersAtEveryRevision()');
        $config = $app->make(RevisionsConfig::class);
        $shapes = $app->make(Shapes::class);
        $schema = new RevisionSchema(new JsonSchemaExporter($shapes, closed: true));
        foreach ($revisions ?? $config->enum::order() as $revision) {
            $rendered = $revision::using($revision, static fn(): mixed => is_subclass_of($class, JsonResource::class)
                ? (new $class($subject))->resolve()
                : $app->make(Projector::class)->project($shapes->for($class, DeclarationKind::Response, $revision), $class::describeRevisioned(DeclarationKind::Response, $subject), $subject));
            $document = json_decode(json_encode($rendered, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
            // one rendering is one object: an empty one is encoded `[]`, never a list
            $schema->assertMatches($document === [] ? new stdClass() : $document, $class, $revision);
        }
    }

    /**
     * Adds the revision of `withRevision()` to the query of the request in query mode, unless the request names the
     * parameter itself (its fragment kept), then prepares it as Laravel does.
     *
     * @param Uri|string $uri
     *
     * @return string
     */
    protected function prepareUrlForRequest($uri)
    {
        if ($this->revisionQuery !== null) {
            [$parameter, $value] = $this->revisionQuery;
            $uri = (string) $uri;
            $fragment = '';
            if (($hash = strpos($uri, '#')) !== false) {
                $fragment = substr($uri, $hash);
                $uri = substr($uri, 0, $hash);
            }
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            // a revision the request names itself wins
            if (!array_key_exists($parameter, $query)) {
                $uri .= (str_contains($uri, '?') ? '&' : '?') . http_build_query([$parameter => $value]);
            }
            $uri .= $fragment;
        }

        return parent::prepareUrlForRequest($uri);
    }

    /**
     * The application of the running test; a `LogicException` naming `$helper` outside a test.
     */
    private function revisionsApplication(string $helper): Application
    {
        return $this->app ?? throw new LogicException("{$helper} needs the application: call it in a test, after setUp().");
    }

    /**
     * The `name` option of the resolver `$resolver`, `$default` when not set; `ConfigurationException::invalidValue()`
     * when it is not a non-empty string.
     *
     * @param array<string, mixed> $options
     */
    private static function revisionOption(array $options, string $resolver, string $default): string
    {
        $value = $options['name'] ?? $default;
        if (!is_string($value) || $value === '') {
            throw ConfigurationException::invalidValue("resolvers.{$resolver}.name", 'a non-empty string', get_debug_type($value));
        }

        return $value;
    }

    /**
     * Fails with every diagnostic of the check (`revision:check`) when one is an error; warnings alone pass.
     */
    public function assertRevisionsAreConsistent(): void
    {
        $app = $this->revisionsApplication('assertRevisionsAreConsistent()');
        $diagnostics = $app->make(Checker::class)->run();
        $errors = count($diagnostics->errors());
        $lines = [sprintf(
            'The revisions are inconsistent (%d %s, %d %s):',
            $errors,
            $errors === 1 ? 'error' : 'errors',
            count($diagnostics->warnings()),
            count($diagnostics->warnings()) === 1 ? 'warning' : 'warnings',
        )];
        foreach ($diagnostics as $diagnostic) {
            $lines[] = sprintf(
                '[%s] %s %s%s%s: %s',
                $diagnostic->severity->value,
                $diagnostic->code,
                $diagnostic->class,
                $diagnostic->field === null ? '' : " '{$diagnostic->field}'",
                $diagnostic->revision === null ? '' : " at {$diagnostic->revision}",
                $diagnostic->message,
            );
        }

        Assert::assertFalse($diagnostics->hasErrors(), implode("\n", $lines));
    }
}
