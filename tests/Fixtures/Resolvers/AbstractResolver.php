<?php

declare(strict_types=1);

namespace Test\Fixtures\Resolvers;

use Ark4ne\ApiRevisions\Laravel\Http\RevisionResolver;

/**
 * An abstract class implementing `RevisionResolver`: refused by the configuration, the container cannot build it.
 */
abstract class AbstractResolver implements RevisionResolver {}
