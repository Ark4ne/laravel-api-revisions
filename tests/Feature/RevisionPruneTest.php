<?php

declare(strict_types=1);

namespace Test\Feature;

use Illuminate\Testing\PendingCommand;
use Test\FeatureTestCase;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\InvalidDeclaration;
use Test\Fixtures\Declarations\PrunableDeclaration;
use Test\Fixtures\Requests\StoreOrderRequest;
use Test\Fixtures\Resources\OrderResource;

final class RevisionPruneTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->application()['config']->set('revisions.enum', ApiRevision::class);
    }

    public function test_the_prune_command_lists_each_dead_declaration_with_its_location(): void
    {
        $this->discover([PrunableDeclaration::class, OrderResource::class]);
        $file = (string) realpath(__DIR__ . '/../Fixtures/Resources/OrderResource.php');
        $line = self::line($file, "'total' =>");

        $this->command('revision:prune')
            ->expectsOutput('Retiring Genesis (the oldest revision) makes 10 declarations dead:')
            ->expectsOutputToContain("- Test\\Fixtures\\Declarations\\PrunableDeclaration request-body 'legacy': until(Genesis), delete the field (")
            ->expectsOutputToContain("- Test\\Fixtures\\Resources\\OrderResource response 'total': was(Genesis), delete the was() ({$file}:{$line})")
            ->assertSuccessful();
    }

    public function test_the_methods_of_a_request_are_searched(): void
    {
        $this->discover([StoreOrderRequest::class]);
        $file = (string) realpath(__DIR__ . '/../Fixtures/Requests/StoreOrderRequest.php');

        $this->command('revision:prune')
            ->expectsOutputToContain("request-body 'quantity': was(Genesis), delete the was() ({$file}:" . self::line($file, "'quantity' =>") . ')')
            ->expectsOutputToContain("request-query 'per_page': was(Genesis), delete the was() ({$file}:" . self::line($file, "'per_page' =>") . ')')
            ->assertSuccessful();
    }

    public function test_the_location_is_relative_to_the_application(): void
    {
        $this->discover([OrderResource::class]);
        $this->application()->setBasePath((string) realpath(__DIR__ . '/..'));

        $this->command('revision:prune')->expectsOutputToContain('(Fixtures/Resources/OrderResource.php:')->assertSuccessful();
    }

    public function test_nothing_to_prune_is_said_and_succeeds(): void
    {
        $this->discover([CustomerDeclaration::class]);

        $this->command('revision:prune')->expectsOutputToContain('Nothing to prune.')->assertSuccessful();
    }

    public function test_a_class_that_does_not_build_is_skipped_with_a_warning(): void
    {
        $this->discover([InvalidDeclaration::class, CustomerDeclaration::class]);

        $this->command('revision:prune')
            ->expectsOutputToContain(InvalidDeclaration::class . ' skipped, it does not build:')
            ->expectsOutputToContain('Nothing to prune.')
            ->assertSuccessful();
    }

    /**
     * @param list<class-string> $classes
     */
    private function discover(array $classes): void
    {
        $this->application()['config']->set('revisions.discovery', ['paths' => [], 'classes' => $classes]);
    }

    private static function line(string $file, string $needle): int
    {
        $source = (string) file_get_contents($file);
        $offset = strpos($source, $needle);
        self::assertNotFalse($offset, $needle);

        return substr_count($source, "\n", 0, $offset) + 1;
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
