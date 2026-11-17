<?php

declare(strict_types=1);

namespace Ark4ne\ApiRevisions\Laravel;

use Ark4ne\ApiRevisions\Configuration\Configuration;
use Ark4ne\ApiRevisions\Configuration\MissingDefault;
use Ark4ne\ApiRevisions\Exceptions\ConfigurationException;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\HeaderResolver;
use Ark4ne\ApiRevisions\Laravel\Http\Resolvers\PathResolver;
use Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver;
use Ark4ne\ApiRevisions\Revisions\Revision;
use Ark4ne\ApiRevisions\Revisions\RevisionOrder;
use Ark4ne\ApiRevisions\Revisions\Unrevisioned;
use ReflectionClass;

/**
 * `config/revisions.php` validated: the revision enum, the options of the Laravel layer and the core
 * `Configuration` built from it. Immutable.
 */
final readonly class RevisionsConfig
{
    /**
     * @param class-string<Revision> $enum
     * @param list<array{class-string<RevisionResolver>, array<string, mixed>}> $resolvers each resolver class with its
     *                                                                            options (named constructor arguments)
     * @param Revision|class-string<RevisionResolver> $default a fixed revision (`latest`, `oldest` and backed values
     *                                                         resolved), or a resolver class
     * @param non-empty-string $responseHeader
     * @param list<string> $discoveryPaths
     * @param list<string> $discoveryClasses
     * @param non-empty-string $cachePath
     * @param non-empty-string $lockPath
     * @param bool $revisioned whether the application configured its enum: `false` for the implicit `Unrevisioned`, invisible to clients
     */
    private function __construct(
        public string $enum,
        public array $resolvers,
        public Revision|string $default,
        public string $responseHeader,
        public Configuration $configuration,
        public array $discoveryPaths,
        public array $discoveryClasses,
        public string $cachePath,
        public string $lockPath,
        public bool $revisioned = true,
    ) {}

    /**
     * The configuration, every key checked: a missing key takes the default of `config/revisions.php` (relative paths,
     * no discovery path); a wrong one throws `ConfigurationException`. Without `enum`, the implicit `Unrevisioned`
     * and no resolver.
     *
     * @param array<array-key, mixed> $config the `revisions` configuration
     * @param bool $debug `app.debug`: the value errors are strict when `strict_values` is `null`
     */
    public static function fromArray(array $config, bool $debug = false): self
    {
        $revisioned = ($config['enum'] ?? null) !== null;
        $enum = $revisioned ? self::enum($config['enum']) : Unrevisioned::class;
        $strict = $config['strict_values'] ?? null;
        if ($strict !== null && !is_bool($strict)) {
            throw ConfigurationException::invalidValue('strict_values', 'a bool or null', get_debug_type($strict));
        }
        $missing = $config['missing_default'] ?? 'error';
        $missingDefault = is_string($missing) ? MissingDefault::tryFrom($missing) : null;
        if ($missingDefault === null) {
            throw ConfigurationException::invalidValue('missing_default', "'error' or 'null'", get_debug_type($missing));
        }
        $resolvers = $config['resolvers'] ?? [HeaderResolver::class => []];
        if (!is_array($resolvers)) {
            throw ConfigurationException::invalidValue('resolvers', 'an array', get_debug_type($resolvers));
        }
        $discovery = $config['discovery'] ?? [];
        if (!is_array($discovery)) {
            throw ConfigurationException::invalidValue('discovery', 'an array', get_debug_type($discovery));
        }

        return new self(
            $enum,
            $revisioned ? self::resolvers($resolvers) : [],
            $revisioned ? self::default($enum, $config['default'] ?? 'latest') : self::unrevisionedDefault($config['default'] ?? 'latest'),
            self::header(self::string($config, 'response_header', 'Api-Revision')),
            new Configuration(
                self::string($config, 'date_format', 'Y-m-d'),
                self::string($config, 'date_time_format', 'Y-m-d\TH:i:sP'),
                $missingDefault,
                $strict ?? $debug,
            ),
            self::strings($discovery, 'paths'),
            self::strings($discovery, 'classes'),
            self::string($config, 'cache_path', 'bootstrap/cache/revisions.php'),
            self::string($config, 'lock_path', 'revisions.lock'),
            $revisioned,
        );
    }

    /**
     * The revision outside a request: the default when it is a fixed revision, `null` (the head) for a resolver
     * class.
     */
    public function fixedDefault(): ?Revision
    {
        return $this->default instanceof Revision ? $this->default : null;
    }

    /**
     * The route parameter of path mode: the `parameter` option of the first `PathResolver` of `resolvers` (`revision`
     * by default), else `revision` when the `default` is the `PathResolver` class; `ConfigurationException::pathModeWithoutResolver()` without one, `invalidValue()` when the option is
     * not a non-empty string.
     */
    public function pathParameter(): string
    {
        if (!$this->revisioned) {
            throw ConfigurationException::pathModeWithoutEnum();
        }
        foreach ($this->resolvers as [$class, $options]) {
            if ($class === PathResolver::class) {
                $parameter = $options['parameter'] ?? 'revision';
                if (!is_string($parameter) || $parameter === '') {
                    throw ConfigurationException::invalidValue('resolvers.PathResolver.parameter', 'a non-empty string', get_debug_type($parameter));
                }

                return $parameter;
            }
        }

        if ($this->default === PathResolver::class) {
            return 'revision';
        }

        throw ConfigurationException::pathModeWithoutResolver();
    }

    /**
     * @return class-string<Revision>
     */
    private static function enum(mixed $enum): string
    {
        if (!is_string($enum) || !enum_exists($enum) || !is_subclass_of($enum, Revision::class)) {
            throw ConfigurationException::notARevisionEnum(is_string($enum) ? $enum : get_debug_type($enum));
        }

        return $enum;
    }

    /**
     * `latest`, `oldest` or a backed value as its revision (the keywords first: a case backed by `'latest'` is reached
     * by the keyword); a class implementing `RevisionResolver` as it is.
     *
     * @param class-string<Revision> $enum
     *
     * @return Revision|class-string<RevisionResolver>
     */
    private static function default(string $enum, mixed $default): Revision|string
    {
        if ($default === 'latest') {
            return RevisionOrder::latest($enum);
        }
        if ($default === 'oldest') {
            return RevisionOrder::oldest($enum);
        }
        if (is_int($default) || is_string($default)) {
            foreach ($enum::cases() as $case) {
                if ((string) $case->value === (string) $default) {
                    return $case;
                }
            }
            if (is_string($default) && self::isResolver($default)) {
                return $default;
            }
        }

        throw ConfigurationException::invalidValue('default', "'latest', 'oldest', a backed value of {$enum} or a resolver class", is_string($default) ? $default : get_debug_type($default));
    }

    /**
     * The default without configured enum: `latest` or `oldest`, the one implicit revision either way.
     */
    private static function unrevisionedDefault(mixed $default): Revision
    {
        if ($default !== 'latest' && $default !== 'oldest') {
            throw ConfigurationException::invalidValue('default', "'latest' or 'oldest' (no revision enum configured)", is_string($default) ? $default : get_debug_type($default));
        }

        return Unrevisioned::Head;
    }

    /**
     * Each resolver with its options: `Class::class => [options by name]`, or `Class::class` alone.
     *
     * @param array<array-key, mixed> $resolvers
     *
     * @return list<array{class-string<RevisionResolver>, array<string, mixed>}>
     */
    private static function resolvers(array $resolvers): array
    {
        $chain = [];
        foreach ($resolvers as $key => $value) {
            [$class, $options] = is_int($key) ? [$value, []] : [$key, $value];
            if (!is_string($class) || !self::isResolver($class)) {
                $given = is_string($class) ? $class : get_debug_type($class);
            } elseif (!is_array($options) || ($options !== [] && array_is_list($options))) {
                $given = is_array($options) ? 'list' : get_debug_type($options);
            } else {
                /** @var array<string, mixed> $options */
                $chain[] = [$class, $options];

                continue;
            }

            throw ConfigurationException::invalidValue('resolvers', 'classes implementing ' . RevisionResolver::class . ', each optionally => its options by name', $given);
        }

        return $chain;
    }

    /**
     * @param array<array-key, mixed> $config
     * @param non-empty-string $default
     *
     * @return non-empty-string
     */
    private static function string(array $config, string $key, string $default): string
    {
        $value = array_key_exists($key, $config) ? $config[$key] : $default;
        if (!is_string($value) || $value === '') {
            throw ConfigurationException::invalidValue($key, 'a non-empty string', get_debug_type($value));
        }

        return $value;
    }

    /**
     * @param non-empty-string $name
     *
     * @return non-empty-string
     */
    private static function header(string $name): string
    {
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1) {
            throw ConfigurationException::invalidValue('response_header', 'a header name (an HTTP token)', 'string');
        }

        return $name;
    }

    /**
     * @param array<array-key, mixed> $discovery
     *
     * @return list<string>
     */
    private static function strings(array $discovery, string $key): array
    {
        $values = $discovery[$key] ?? [];
        if (!is_array($values) || !array_is_list($values) || array_filter($values, is_string(...)) !== $values) {
            throw ConfigurationException::invalidValue("discovery.{$key}", 'a list of strings', get_debug_type($values));
        }

        /** @var list<string> */
        return $values;
    }

    /**
     * Whether `$class` is a class the container can build implementing `RevisionResolver`.
     *
     * @phpstan-assert-if-true class-string<RevisionResolver> $class
     */
    private static function isResolver(string $class): bool
    {
        return is_subclass_of($class, RevisionResolver::class) && (new ReflectionClass($class))->isInstantiable();
    }
}
