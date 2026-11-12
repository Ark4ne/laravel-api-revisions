<?php

declare(strict_types=1);

namespace Test\Fixtures\Discovery;

use Ark4ne\ApiRevisions\Schema\Revisioned;

/**
 * An interface extending `Revisioned`: never discovered (US 5.3).
 */
interface Declares extends Revisioned {}
