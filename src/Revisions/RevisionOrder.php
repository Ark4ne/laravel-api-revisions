<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Revisions;

use Ark4ne\ApiRevisions\Exceptions\DefinitionException;
use Ark4ne\ApiRevisions\Support\Memo;

/**
 * Compares revisions by their index in `order()`: the single timeline of the package. Pure; the order of each enum is
 * checked on first use (`DefinitionException::invalidOrder()`) and memoized per enum class (`Support\Memo`). Cases
 * are compared by name, never by backed value (int- and string-backed enums alike). Revisions of two different enums
 * never compare (`DefinitionException::mixedRevisions()`).
 *
 * `previous()`, `next()` and `between()` return `Revision`, not a template: PHPStan would bind it to the case passed
 * (`ApiRevision::Atlas`), not to its enum. `IsRevision` gives applications the precise types.
 */
final class RevisionOrder
{
    /**
     * Position of the revision in `order()`, from 0 (the oldest).
     */
    public static function index(Revision $revision): int
    {
        return self::of($revision::class)['indexes'][$revision->name];
    }

    /**
     * Negative when `$a` is older than `$b`, 0 when equal, positive when newer.
     */
    public static function compare(Revision $a, Revision $b): int
    {
        self::assertSameEnum($a, $b);

        return self::index($a) <=> self::index($b);
    }

    /**
     * The revision just before this one (a case of the same enum), `null` for the oldest.
     */
    public static function previous(Revision $revision): ?Revision
    {
        return self::at($revision::class, self::index($revision) - 1);
    }

    /**
     * The revision just after this one (a case of the same enum), `null` for the head.
     */
    public static function next(Revision $revision): ?Revision
    {
        return self::at($revision::class, self::index($revision) + 1);
    }

    /**
     * @template T of Revision
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function latest(string $class): Revision
    {
        $cases = self::of($class)['cases'];

        return $cases[array_key_last($cases)];
    }

    /**
     * @template T of Revision
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function oldest(string $class): Revision
    {
        return self::of($class)['cases'][0];
    }

    /**
     * Revisions from `$from` to `$to`, both included, oldest first; empty when `$from` is newer than `$to`.
     *
     * @return list<Revision> cases of the enum of `$from` and `$to`
     */
    public static function between(Revision $from, Revision $to): array
    {
        self::assertSameEnum($from, $to);

        $start = self::index($from);
        $length = self::index($to) - $start + 1;

        return $length > 0 ? array_slice(self::of($from::class)['cases'], $start, $length) : [];
    }

    /**
     * @param class-string<Revision> $class
     */
    private static function at(string $class, int $index): ?Revision
    {
        return self::of($class)['cases'][$index] ?? null;
    }

    private static function assertSameEnum(Revision $a, Revision $b): void
    {
        if ($a::class !== $b::class) {
            throw DefinitionException::mixedRevisions($a, $b);
        }
    }

    /**
     * The checked order of an enum: its cases oldest first, and the index of each case by name.
     *
     * @template T of Revision
     *
     * @param class-string<T> $class
     *
     * @return array{cases: non-empty-list<T>, indexes: array<string, int>}
     */
    private static function of(string $class): array
    {
        return Memo::remember('revision-order:' . $class, static function () use ($class): array {
            $order = self::declaredOrder($class);
            if (!array_is_list($order)) {
                throw DefinitionException::invalidOrder($class, 'the order is not a list');
            }

            $cases = [];
            $indexes = [];
            foreach ($order as $case) {
                if (!$case instanceof $class) {
                    throw DefinitionException::invalidOrder($class, sprintf('%s is not a case of the enum', get_debug_type($case)));
                }
                if (isset($indexes[$case->name])) {
                    throw DefinitionException::invalidOrder($class, "{$case->name} is listed twice");
                }
                $indexes[$case->name] = count($cases);
                $cases[] = $case;
            }

            if ($cases === []) {
                throw DefinitionException::invalidOrder($class, 'the order is empty');
            }

            foreach ($class::cases() as $case) {
                if (!isset($indexes[$case->name])) {
                    throw DefinitionException::invalidOrder($class, "{$case->name} is missing");
                }
            }

            return ['cases' => $cases, 'indexes' => $indexes];
        });
    }

    /**
     * The `order()` of an enum, as the application wrote it: checked, never trusted.
     *
     * @param class-string<Revision> $class
     *
     * @return array<mixed>
     */
    private static function declaredOrder(string $class): array
    {
        return $class::order();
    }
}
