<?php

declare(strict_types=1);

namespace Test\Fixtures\Discovery;

use Ark4ne\ApiRevisions\Schema\Revisioned;

/**
 * An abstract class implementing `Revisioned`: never discovered.
 */
abstract class AbstractDeclaration implements Revisioned {}
