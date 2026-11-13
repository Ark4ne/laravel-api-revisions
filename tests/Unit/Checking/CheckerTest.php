<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\TestCase;

final class CheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CountingDeclaration::$described = 0;
        CountingDeclaration::$fail = false;
    }

    public function test_the_checker_runs_every_check_on_every_class(): void
    {
        $first = self::recording('first');
        $second = self::recording('second');
        $checker = new Checker(ApiRevision::class, self::finder([CountingDeclaration::class, CustomerDeclaration::class]), [$first, $second]);

        $diagnostics = $checker->run();

        self::assertSame(
            [
                ['first', ApiRevision::class],
                ['second', ApiRevision::class],
                ['first', CountingDeclaration::class],
                ['second', CountingDeclaration::class],
                ['first', CustomerDeclaration::class],
                ['second', CustomerDeclaration::class],
            ],
            array_map(static fn($diagnostic): array => [$diagnostic->code, $diagnostic->class], $diagnostics->all()),
        );
    }

    public function test_the_checks_of_a_run_share_its_shapes(): void
    {
        $compiling = self::compiling();
        $checker = new Checker(ApiRevision::class, self::finder([CountingDeclaration::class]), [$compiling, $compiling]);

        $checker->run();
        self::assertSame(1, CountingDeclaration::$described);

        $checker->run();
        self::assertSame(2, CountingDeclaration::$described);
    }

    public function test_without_class_only_the_enum_is_checked(): void
    {
        $diagnostics = (new Checker(ApiRevision::class, self::finder([]), [self::recording('only')]))->run();

        self::assertCount(1, $diagnostics);
        self::assertSame(ApiRevision::class, $diagnostics->all()[0]->class);
    }

    /**
     * A check adding one warning per target, its own name as code.
     */
    private static function recording(string $name): Check
    {
        return new readonly class ($name) implements Check {
            public function __construct(private string $name) {}

            public function run(CheckContext $context): void
            {
                $context->warning($this->name, 'Ran.');
            }
        };
    }

    /**
     * A check compiling the response of every declaring class at the head.
     */
    private static function compiling(): Check
    {
        return new class implements Check {
            public function run(CheckContext $context): void
            {
                if ($context->class !== null) {
                    $context->shape(DeclarationKind::Response, ApiRevision::latest());
                }
            }
        };
    }

    /**
     * @param list<class-string<Revisioned>> $classes
     */
    private static function finder(array $classes): ClassFinder
    {
        return new readonly class ($classes) implements ClassFinder {
            /**
             * @param list<class-string<Revisioned>> $classes
             */
            public function __construct(private array $classes) {}

            public function find(): array
            {
                return $this->classes;
            }
        };
    }
}
