<?php

declare(strict_types=1);

namespace Test\Unit\Compilation;

use Ark4ne\ApiRevisions\Compilation\Probe;
use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Fixtures\Declarations\OrderDeclaration;
use Test\TestCase;

final class ProbeTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(Probe): mixed, string}>
     */
    public static function accesses(): iterable
    {
        // @phpstan-ignore property.notFound (the access under test)
        yield 'property' => [static fn(Probe $probe): mixed => $probe->title, '->title'];
        // @phpstan-ignore property.notFound (the access under test)
        yield 'isset' => [static fn(Probe $probe): bool => isset($probe->title), 'isset(->title)'];
        yield 'assignment' => [static function (Probe $probe): void {
            // @phpstan-ignore property.notFound (the access under test)
            $probe->title = 'Draft';
        }, '->title ='];
        yield 'unset' => [static function (Probe $probe): void {
            // @phpstan-ignore property.notFound (the access under test)
            unset($probe->title);
        }, 'unset(->title)'];
        // @phpstan-ignore property.private (the access under test: the class it names stays hidden)
        yield 'class' => [static fn(Probe $probe): mixed => $probe->class, '->class'];
        // @phpstan-ignore method.notFound (the access under test)
        yield 'method' => [static fn(Probe $probe): mixed => $probe->fullName('x'), '->fullName()'];
        yield 'offset' => [static fn(Probe $probe): mixed => $probe['title'], "['title']"];
        yield 'offset isset' => [static fn(Probe $probe): bool => isset($probe['title']), "isset(['title'])"];
        yield 'offset assignment' => [static function (Probe $probe): void {
            $probe['title'] = 'Draft';
        }, "['title'] ="];
        yield 'offset unset' => [static function (Probe $probe): void {
            unset($probe['title']);
        }, "unset(['title'])"];
        yield 'count' => [static fn(Probe $probe): int => count($probe), 'count()'];
        yield 'iteration' => [static fn(Probe $probe): array => iterator_to_array($probe), 'iteration'];
        yield 'string' => [static fn(Probe $probe): string => (string) $probe, 'string cast'];
    }

    /**
     * @param Closure(Probe): mixed $access
     */
    #[DataProvider('accesses')]
    public function test_every_access_to_the_probe_throws(Closure $access, string $named): void
    {
        $this->expectExceptionObject(DefinitionException::subjectReadAtCompilation(OrderDeclaration::class, $named));

        $access(new Probe(OrderDeclaration::class));
    }
}
