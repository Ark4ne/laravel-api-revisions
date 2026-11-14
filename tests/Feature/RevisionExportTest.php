<?php

declare(strict_types=1);

namespace Test\Feature;

use Ark4ne\ApiRevisions\Compilation\Shapes;
use Ark4ne\ApiRevisions\Export\JsonSchemaExporter;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Schema;
use Illuminate\Testing\PendingCommand;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\EagerDeclaration;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\Fixtures\Discovery\AbstractDeclaration;
use Test\Fixtures\Order;

final class RevisionExportTest extends FeatureTestCase
{
    private const int FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->application()['config'];
        $config->set('revisions.enum', ApiRevision::class);
        $config->set('revisions.discovery', ['paths' => [], 'classes' => [CustomerDeclaration::class]]);
    }

    public function test_the_export_command_prints_every_kind_of_every_class_at_every_revision(): void
    {
        $exporter = new JsonSchemaExporter(new Shapes());
        $expected = [];
        foreach (ApiRevision::order() as $revision) {
            foreach ([DeclarationKind::Response, DeclarationKind::RequestBody] as $kind) {
                $expected[$revision->value][CustomerDeclaration::class][$kind->value] = $exporter->export(CustomerDeclaration::class, $kind, $revision);
            }
        }

        $this->command('revision:export')->expectsOutput(json_encode($expected, self::FLAGS))->assertSuccessful();
    }

    public function test_options_narrow_the_export(): void
    {
        $exporter = new JsonSchemaExporter(new Shapes());

        $this->command('revision:export', ['--revision' => 'winter-25'])
            ->expectsOutput(json_encode(['winter-25' => [CustomerDeclaration::class => [
                'response' => $exporter->export(CustomerDeclaration::class, DeclarationKind::Response, ApiRevision::Winter25),
                'request-body' => $exporter->export(CustomerDeclaration::class, DeclarationKind::RequestBody, ApiRevision::Winter25),
            ]]], self::FLAGS))
            ->assertSuccessful();
    }

    public function test_one_revision_one_class_and_a_single_kind_print_the_schema_alone(): void
    {
        $schema = (new JsonSchemaExporter(new Shapes()))->export(OrderDeclaration::class, DeclarationKind::Response, ApiRevision::Atlas);

        $this->command('revision:export', ['--revision' => 'atlas', '--class' => OrderDeclaration::class])
            ->expectsOutput(json_encode($schema, self::FLAGS))
            ->assertSuccessful();
    }

    public function test_an_any_field_is_exported_as_an_empty_schema_object(): void
    {
        CheckedDeclaration::$describe = static fn(): array => ['any' => Schema::rule([])];

        try {
            $this->command('revision:export', ['--revision' => 'atlas', '--class' => CheckedDeclaration::class])
                ->expectsOutputToContain('"any": {}')
                ->assertSuccessful();
        } finally {
            CheckedDeclaration::reset();
        }
    }

    public function test_no_class_exports_an_empty_object(): void
    {
        $this->application()['config']->set('revisions.discovery.classes', []);

        $this->command('revision:export', ['--revision' => 'atlas'])->expectsOutput('{}')->assertSuccessful();
    }

    public function test_an_unknown_revision_is_refused(): void
    {
        $this->command('revision:export', ['--revision' => 'nope'])
            ->expectsOutputToContain('Unknown revision "nope": genesis, winter-25, spring-26, atlas.')
            ->assertFailed();
    }

    public function test_an_abstract_class_is_refused(): void
    {
        $this->command('revision:export', ['--class' => AbstractDeclaration::class])
            ->expectsOutputToContain(AbstractDeclaration::class . ' is not a class implementing Ark4ne\\ApiRevisions\\Schema\\Revisioned.')
            ->assertFailed();
    }

    public function test_a_declaration_error_fails_the_export_with_its_reason(): void
    {
        $this->command('revision:export', ['--class' => EagerDeclaration::class])
            ->expectsOutputToContain('read its subject (->title) while compiling')
            ->assertFailed();
    }

    public function test_a_class_that_is_not_revisioned_is_refused(): void
    {
        $this->command('revision:export', ['--class' => Order::class])
            ->expectsOutputToContain(Order::class . ' is not a class implementing Ark4ne\ApiRevisions\Schema\Revisioned.')
            ->assertFailed();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
