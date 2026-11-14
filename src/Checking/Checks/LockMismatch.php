<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Checking\Checks;

use Ark4ne\ApiRevisions\Checking\Check;
use Ark4ne\ApiRevisions\Checking\CheckContext;
use Ark4ne\ApiRevisions\Checking\Lock;
use Ark4ne\ApiRevisions\Checking\LockedRevision;
use Ark4ne\ApiRevisions\Exceptions\LockException;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Schema\DeclarationKind;
use Ark4ne\ApiRevisions\Schema\HasLifetime;
use Throwable;

/**
 * `lock-mismatch` (model.md §9, D69): the structure of a class at a locked revision differs from its lock — per
 * top-level field added, removed or changed, per other keyword, per class or kind added or removed (a class absent at
 * a revision by its lifetime, model.md D81, is compared only when the snapshot holds it). On the enum: the
 * locked classes no longer discovered, and the lock files that cannot be read. The snapshots are read once per run,
 * on the enum (checked first by every run).
 */
final class LockMismatch implements Check
{
    public const string CODE = 'lock-mismatch';

    /**
     * @var list<LockedRevision> the readable snapshots of the run: read on the enum, which every run checks first,
     *                           kept for its classes
     */
    private array $snapshots = [];

    public function __construct(private readonly Lock $lock) {}

    public function run(CheckContext $context): void
    {
        if ($context->class === null) {
            $this->enum($context);

            return;
        }
        $order = $context->order();
        foreach ($this->snapshots as $snapshot) {
            $revision = $context->revision($snapshot->revision);
            if ($revision === null) {
                continue;
            }
            if (!in_array($revision, $order, true)) {
                if (isset($snapshot->classes[$context->class])) {
                    $context->error(self::CODE, "The class is gone from the locked revision {$snapshot->revision}: " . self::relock($snapshot->revision), revision: $revision);
                }

                continue;
            }
            try {
                $this->compareClass($context, $revision, $snapshot);
            } catch (Throwable) {
                // a nested resource that does not build: reported by its own build (D66)
                continue;
            }
        }
    }

    /**
     * On the enum: reads the snapshots of the run; reports the lock files that cannot be read and the locked classes
     * no longer discovered.
     */
    private function enum(CheckContext $context): void
    {
        $this->snapshots = [];
        foreach ($this->lock->locked() as $value) {
            $revision = $context->revision($value);
            if ($revision === null) {
                // reported by lock-order-changed
                continue;
            }
            try {
                $snapshot = $this->lock->read($value);
            } catch (LockException $error) {
                $context->error(self::CODE, $error->getMessage(), revision: $revision);

                continue;
            }
            $this->snapshots[] = $snapshot;
            foreach (array_diff(array_keys($snapshot->classes), $context->classes) as $class) {
                $context->error(self::CODE, "{$class} is gone from the locked revision {$value}: " . self::relock($value), revision: $revision);
            }
        }
    }

    /**
     * Compares every kind of the checked class at `$revision` with its snapshot. A class or a kind the snapshot does
     * not hold is reported only when it has fields at `$revision` (D69).
     */
    private function compareClass(CheckContext $context, Revision $revision, LockedRevision $snapshot): void
    {
        $value = (string) $revision->value;
        $kinds = $snapshot->classes[(string) $context->class] ?? null;
        $declared = [];
        foreach ($context->kinds() as $kind) {
            $declared[] = $kind->value;
            if (isset($kinds[$kind->value])) {
                $this->compare($context, $kind, $revision, $kinds[$kind->value]);
            } elseif ($context->shape($kind, $revision)->fields !== []) {
                $what = $kinds === null ? 'class' : $kind->value;
                $bound = $kinds === null && is_subclass_of((string) $context->class, HasLifetime::class) ? 'its revisionedLifetime()' : 'the since() of its fields';
                $context->error(self::CODE, "The {$what} is new in the locked revision {$value}: declare {$bound}, or lock {$value} again.", revision: $revision);
                if ($kinds === null) {
                    return;
                }
            }
        }
        foreach (array_diff(array_keys($kinds ?? []), $declared) as $kind) {
            $context->error(self::CODE, "The {$kind} is gone from the locked revision {$value}: " . self::relock($value), revision: $revision);
        }
    }

    /**
     * Compares the structure of `$kind` at `$revision` with its lock: each top-level field (its schema, whether it is
     * required) under its head key, then each other keyword.
     *
     * @param array<string, mixed> $locked
     */
    private function compare(CheckContext $context, DeclarationKind $kind, Revision $revision, array $locked): void
    {
        $value = (string) $revision->value;
        $current = Lock::normalize($context->export($kind, $revision));
        $headKeys = [];
        foreach ($context->shape($kind, $revision)->fields as $field) {
            $headKeys[$field->name] = $field->headKey;
        }
        $properties = [self::map($current['properties'] ?? []), self::map($locked['properties'] ?? [])];
        $required = [self::map($current['required'] ?? []), self::map($locked['required'] ?? [])];
        $names = array_unique([...array_keys($properties[0]), ...array_keys($properties[1])]);
        sort($names);
        foreach ($names as $name) {
            $name = (string) $name;
            $message = match (true) {
                !array_key_exists($name, $properties[1]) => "'{$name}' ({$kind->value}) is new in the locked revision {$value}: declare its since(), or lock {$value} again.",
                !array_key_exists($name, $properties[0]) => "'{$name}' ({$kind->value}) is gone from the locked revision {$value}: " . self::relock($value),
                $properties[0][$name] !== $properties[1][$name] || in_array($name, $required[0], true) !== in_array($name, $required[1], true) => "'{$name}' ({$kind->value}) differs from the locked revision {$value}: " . self::relock($value),
                default => null,
            };
            if ($message !== null) {
                $context->error(self::CODE, $message, $headKeys[$name] ?? $name, $revision);
            }
        }
        unset($current['properties'], $current['required'], $locked['properties'], $locked['required']);
        $keywords = array_unique([...array_keys($current), ...array_keys($locked)]);
        sort($keywords);
        foreach ($keywords as $keyword) {
            if (($current[$keyword] ?? null) !== ($locked[$keyword] ?? null)) {
                $context->error(self::CODE, "The keyword '{$keyword}' of the {$kind->value} differs from the locked revision {$value}: " . self::relock($value), revision: $revision);
            }
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function relock(string $value): string
    {
        return "lock {$value} again if it is intended.";
    }
}
