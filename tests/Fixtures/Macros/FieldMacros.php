<?php

declare(strict_types=1);

namespace Test\Fixtures\Macros;

use Ark4ne\ApiRevisions\Schema\Descriptor;

/**
 * Descriptor macros (model.md D87), registered with `DescriptorMacros::register('whenFlag', FieldMacros::whenFlag(...))`
 * and mapped in `apiRevisions.macros` for PHPStan.
 */
final class FieldMacros
{
    /**
     * Present when the subject's `$flag` is `true`, like a JSON:API `whenInFields()`: built with `when()`.
     *
     * @template TDescriptor of Descriptor<mixed>
     *
     * @param TDescriptor $descriptor
     *
     * @return TDescriptor
     */
    public static function whenFlag(Descriptor $descriptor, string $flag = 'visible'): Descriptor
    {
        return $descriptor->when(static fn(mixed $subject): bool => is_array($subject) && ($subject[$flag] ?? false) === true);
    }

    /**
     * A description and an example in one call.
     *
     * @template TDescriptor of Descriptor<mixed>
     *
     * @param TDescriptor $descriptor
     *
     * @return TDescriptor
     */
    public static function documented(Descriptor $descriptor, string $description, int|float|string|null $example = null): Descriptor
    {
        $descriptor = $descriptor->describe($description);

        return $example === null ? $descriptor : $descriptor->example($example);
    }

    /**
     * Not a macro PHPStan accepts: an instance method.
     *
     * @param Descriptor<mixed> $descriptor
     *
     * @return Descriptor<mixed>
     */
    public function instance(Descriptor $descriptor): Descriptor
    {
        return $descriptor;
    }

    /**
     * Not a macro PHPStan accepts: no descriptor parameter.
     */
    public static function noDescriptor(): void {}

    /**
     * Not a macro PHPStan accepts: its first parameter cannot take a descriptor.
     */
    public static function notADescriptor(int $count): int
    {
        return $count;
    }

    /**
     * Not a macro PHPStan accepts: a variadic first parameter.
     *
     * @param Descriptor<mixed> ...$descriptors
     */
    public static function variadic(Descriptor ...$descriptors): int
    {
        return count($descriptors);
    }
}
