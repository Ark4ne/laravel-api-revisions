<?php

declare(strict_types=1);

namespace Test\Unit\Checking;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Checking\Checker;
use Ark4ne\ApiRevisions\Checking\Checks\BoundRepeated;
use Ark4ne\ApiRevisions\Checking\Checks\ConversionLossy;
use Ark4ne\ApiRevisions\Checking\Checks\ConversionMissing;
use Ark4ne\ApiRevisions\Checking\Checks\EagerValue;
use Ark4ne\ApiRevisions\Checking\Checks\HistoryAfterUntil;
use Ark4ne\ApiRevisions\Checking\Checks\HistoryBeforeSince;
use Ark4ne\ApiRevisions\Checking\Checks\NameCollision;
use Ark4ne\ApiRevisions\Checking\Checks\OrderDuplicateCase;
use Ark4ne\ApiRevisions\Checking\Checks\OrderMissingCase;
use Ark4ne\ApiRevisions\Checking\Checks\RequiredSinceWithoutDefault;
use Ark4ne\ApiRevisions\Checking\Checks\SchemaOverride;
use Ark4ne\ApiRevisions\Checking\ClassFinder;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\Revisioned;
use Ark4ne\ApiRevisions\Schema\Schema;
use Ark4ne\ApiRevisions\Support\Diagnostic;
use Ark4ne\ApiRevisions\Support\Severity;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\ApiRevision;
use Test\Fixtures\Declarations\CheckedDeclaration;
use Test\Fixtures\Declarations\CountingDeclaration;
use Test\Fixtures\Declarations\CustomerDeclaration;
use Test\Fixtures\Declarations\StoreOrderDeclaration;
use Test\Fixtures\Order;
use Test\Fixtures\Revisions\ForeignCaseRevision;
use Test\Fixtures\Revisions\MissingCaseRevision;
use Test\TestCase;

final class CheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CountingDeclaration::$described = 0;
        CountingDeclaration::$fail = false;
        CheckedDeclaration::reset();
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

        // built once per run: its descriptors and its shape at every revision, for its 3 kinds; the checks describe and
        // compile nothing more
        $built = 3 * (1 + count(ApiRevision::order()));

        $checker->run();
        self::assertSame($built, CountingDeclaration::$described);

        $checker->run();
        self::assertSame(2 * $built, CountingDeclaration::$described);
    }

    public function test_without_class_only_the_enum_is_checked(): void
    {
        $diagnostics = (new Checker(ApiRevision::class, self::finder([]), [self::recording('only')]))->run();

        self::assertCount(1, $diagnostics);
        self::assertSame(ApiRevision::class, $diagnostics->all()[0]->class);
    }

    public function test_a_declaration_error_is_reported_and_the_check_goes_on(): void
    {
        CheckedDeclaration::$describe = static fn(): array => ['total' => Schema::int()->since(ApiRevision::Atlas)->until(ApiRevision::Genesis)];
        $checker = new Checker(ApiRevision::class, self::finder([CheckedDeclaration::class, CountingDeclaration::class]), [self::recording('ran')]);

        self::assertSame(
            [
                ['ran', 'warning', ApiRevision::class, null],
                ['lifetime-inverted', 'error', CheckedDeclaration::class, 'A field cannot exist from Test\\Fixtures\\ApiRevision::Atlas until Test\\Fixtures\\ApiRevision::Genesis: its since is newer than its until.'],
                ['ran', 'warning', CountingDeclaration::class, null],
            ],
            array_map(
                static fn($diagnostic): array => [$diagnostic->code, $diagnostic->severity->value, $diagnostic->class, $diagnostic->code === 'ran' ? null : $diagnostic->message],
                $checker->run()->all(),
            ),
        );
    }

    /**
     * @return iterable<string, array{Closure(): array<string, mixed>, string}>
     */
    public static function declarationErrors(): iterable
    {
        yield 'lifetime-inverted' => [static fn(): array => ['a' => Schema::int()->since(ApiRevision::Atlas)->until(ApiRevision::Genesis)], 'lifetime-inverted'];
        yield 'since-older-than-group' => [static fn(): array => Schema::since(ApiRevision::Atlas, ['a' => Schema::int()->since(ApiRevision::Genesis)]), 'since-older-than-group'];
        yield 'until-newer-than-group' => [static fn(): array => Schema::until(ApiRevision::Genesis, ['a' => Schema::int()->until(ApiRevision::Spring26)]), 'until-newer-than-group'];
        yield 'history-unordered' => [static fn(): array => ['a' => Schema::int()->was(ApiRevision::Genesis, name: 'b')->was(ApiRevision::Genesis, name: 'c')], 'history-unordered'];
        yield 'unknown-resource (not revisioned)' => [static fn(): array => ['a' => Schema::resource(Order::class)] /* @phpstan-ignore argument.type (refused at run time) */, 'unknown-resource'];
        yield 'unknown-resource (kind not declared)' => [static fn(): array => ['a' => Schema::resource(StoreOrderDeclaration::class)], 'unknown-resource'];
        yield 'definition-error' => [static fn(): array => ['a' => Schema::int(5)] /* @phpstan-ignore argument.type (refused at run time) */, 'definition-error'];
    }

    /**
     * @param Closure(): array<string, mixed> $describe
     */
    #[DataProvider('declarationErrors')]
    public function test_a_declaration_error_is_reported_under_its_code(Closure $describe, string $code): void
    {
        CheckedDeclaration::$describe = $describe;

        $diagnostics = (new Checker(ApiRevision::class, self::finder([CheckedDeclaration::class]), Checker::builtIn()))->run()->all();

        self::assertCount(1, $diagnostics);
        self::assertSame([$code, CheckedDeclaration::class], [$diagnostics[0]->code, $diagnostics[0]->class]);
    }

    public function test_the_code_of_the_checker_is_the_default_code_of_a_declaration_error(): void
    {
        self::assertSame(Checker::DEFINITION_ERROR, DefinitionException::eagerSource('int')->checkCode);
    }

    public function test_an_invalid_order_is_reported_even_when_another_enum_check_fails(): void
    {
        $failing = new class implements Check {
            public function run(CheckContext $context): void
            {
                $context->error('custom', 'Failed.');
            }
        };

        $diagnostics = (new Checker(ForeignCaseRevision::class, self::finder([]), [$failing]))->run();

        self::assertSame(['custom', 'definition-error'], array_map(static fn($diagnostic): string => $diagnostic->code, $diagnostics->all()));
    }

    public function test_any_exception_building_a_class_is_a_definition_error(): void
    {
        CountingDeclaration::$fail = true;

        $diagnostics = (new Checker(ApiRevision::class, self::finder([CountingDeclaration::class]), [self::recording('ran')]))->run();

        self::assertEquals(
            [
                new Diagnostic('ran', Severity::Warning, ApiRevision::class, null, null, 'Ran.'),
                new Diagnostic('definition-error', Severity::Error, CountingDeclaration::class, null, null, 'The declaration fails.'),
            ],
            $diagnostics->all(),
        );
    }

    public function test_a_class_is_built_at_every_revision_before_its_checks(): void
    {
        // visible at Genesis only: the head compiles, Genesis refuses the resource (unknown-resource)
        CheckedDeclaration::$describe = static fn(): array => ['customer' => Schema::resource(StoreOrderDeclaration::class)->until(ApiRevision::Genesis)];

        $diagnostics = (new Checker(ApiRevision::class, self::finder([CheckedDeclaration::class]), []))->run();

        self::assertEquals(
            [new Diagnostic('unknown-resource', Severity::Error, CheckedDeclaration::class, null, null, "Test\\Fixtures\\Declarations\\StoreOrderDeclaration declares no 'response'.")],
            $diagnostics->all(),
        );
    }

    public function test_an_invalid_order_stops_the_check_after_the_enum(): void
    {
        $diagnostics = (new Checker(MissingCaseRevision::class, self::finder([CountingDeclaration::class]), [new OrderMissingCase()]))->run();

        self::assertSame(0, CountingDeclaration::$described);
        self::assertSame(
            [['order-missing-case', MissingCaseRevision::class]],
            array_map(static fn($diagnostic): array => [$diagnostic->code, $diagnostic->class], $diagnostics->all()),
        );
    }

    public function test_an_invalid_order_no_check_reports_is_a_definition_error_of_the_enum(): void
    {
        $diagnostics = (new Checker(ForeignCaseRevision::class, self::finder([CountingDeclaration::class]), []))->run();

        self::assertSame(0, CountingDeclaration::$described);
        self::assertSame(
            [['definition-error', ForeignCaseRevision::class]],
            array_map(static fn($diagnostic): array => [$diagnostic->code, $diagnostic->class], $diagnostics->all()),
        );
    }

    public function test_the_built_in_checks_cover_every_code_of_the_core(): void
    {
        self::assertSame(
            [
                OrderMissingCase::class,
                OrderDuplicateCase::class,
                BoundRepeated::class,
                HistoryBeforeSince::class,
                HistoryAfterUntil::class,
                ConversionMissing::class,
                ConversionLossy::class,
                NameCollision::class,
                RequiredSinceWithoutDefault::class,
                EagerValue::class,
                SchemaOverride::class,
            ],
            array_map(static fn(Check $check): string => $check::class, Checker::builtIn()),
        );
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
     * A check reading the response of every declaring class: its descriptors and its shape at the head.
     */
    private static function compiling(): Check
    {
        return new class implements Check {
            public function run(CheckContext $context): void
            {
                if ($context->class !== null) {
                    $context->descriptors(DeclarationKind::Response);
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
